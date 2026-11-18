#!/usr/bin/env bash

set -Eeuo pipefail

readonly ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly PACKAGE_NAME="sumup-payment-processor"
readonly OUTPUT_ZIP="${ROOT_DIR}/${PACKAGE_NAME}.zip"
readonly WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PACKAGE_NAME}.build.XXXXXX")"
readonly PACKAGE_DIR="${WORK_DIR}/package/${PACKAGE_NAME}"
readonly TEMP_ZIP="${WORK_DIR}/${PACKAGE_NAME}.zip"

cleanup() {
  rm -rf -- "${WORK_DIR}"
}
trap cleanup EXIT INT TERM

fail() {
  printf 'Build failed: %s\n' "$1" >&2
  exit 1
}

for command in composer php zip unzip; do
  command -v "${command}" >/dev/null 2>&1 || fail "missing command: ${command}"
done

readonly VERSION="$(sed -n 's:.*<version>\([^<]*\)</version>.*:\1:p' "${ROOT_DIR}/info.xml")"
[[ -n "${VERSION}" ]] || fail "info.xml does not contain a version"
if [[ -n "${CI_COMMIT_TAG:-}" && "${CI_COMMIT_TAG}" != "${VERSION}" ]]; then
  fail "tag ${CI_COMMIT_TAG} does not match info.xml version ${VERSION}"
fi

printf 'Preparing production dependencies\n'
mkdir -p "${PACKAGE_DIR}"
cp "${ROOT_DIR}/composer.json" "${ROOT_DIR}/composer.lock" "${PACKAGE_DIR}/"
if [[ -d "${ROOT_DIR}/vendor" ]]; then
  cp -R "${ROOT_DIR}/vendor" "${PACKAGE_DIR}/vendor"
  COMPOSER_DISABLE_NETWORK=1 composer install \
    --working-dir="${PACKAGE_DIR}" \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-plugins \
    --no-scripts \
    --quiet
else
  composer install \
    --working-dir="${PACKAGE_DIR}" \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --no-plugins \
    --no-scripts \
    --quiet
fi

readonly RUNTIME_RESOURCES=(
  CRM
  Civi
  ang
  api
  images
  js
  l10n
  managed
  schema
  settings
  templates
  xml
  LICENSE.txt
  README.md
  info.xml
  sumup_payment_processor.civix.php
  sumup_payment_processor.php
)
for item in "${RUNTIME_RESOURCES[@]}"; do
  [[ -e "${ROOT_DIR}/${item}" ]] || fail "missing runtime resource: ${item}"
  cp -R "${ROOT_DIR}/${item}" "${PACKAGE_DIR}/"
done

php -r '
  $packageDir = $argv[1];
  require $packageDir . "/vendor/autoload.php";
  foreach ([
    "SumUp\\SumUp",
    "SumUp\\Services\\Checkouts",
    "SumUp\\Services\\Readers",
    "SumUp\\Services\\Transactions",
    "SumUp\\Types\\CheckoutSuccess",
    "SumUp\\Types\\TransactionFull",
  ] as $requiredClass) {
    if (!class_exists($requiredClass)) {
      fwrite(STDERR, "Build failed: required SumUp SDK class cannot be autoloaded: {$requiredClass}\n");
      exit(1);
    }
  }
  if (is_dir($packageDir . "/vendor/phpstan")) {
    fwrite(STDERR, "Build failed: dev dependency phpstan must not be packaged\n");
    exit(1);
  }
' "${PACKAGE_DIR}"

find "${PACKAGE_DIR}" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null

readonly REQUIRED_PACKAGE_FILES=(
  info.xml
  sumup_payment_processor.php
  sumup_payment_processor.civix.php
  CRM/Core/Payment/Sumup.php
  CRM/Core/Payment/SumupWebhook.php
  CRM/SumupPaymentProcessor/OAuth.php
  CRM/SumupPaymentProcessor/Page/OAuth.php
  CRM/SumupPaymentProcessor/Page/Widget.php
  js/checkout.js
  ang/afSumUp/sumUp.css
  l10n/fr_FR/LC_MESSAGES/sumup_payment_processor.mo
  vendor/autoload.php
)
for file in "${REQUIRED_PACKAGE_FILES[@]}"; do
  [[ -f "${PACKAGE_DIR}/${file}" ]] || fail "missing packaged file: ${file}"
done

printf 'Packaging %s %s\n' "${PACKAGE_NAME}" "${VERSION}"
(
  cd "${WORK_DIR}/package"
  zip -qr "${TEMP_ZIP}" "${PACKAGE_NAME}" \
    -x '*.DS_Store' '*__MACOSX*'
)
unzip -tq "${TEMP_ZIP}" >/dev/null || fail "ZIP integrity check failed"
if ! unzip -Z1 "${TEMP_ZIP}" | awk -F/ -v root="${PACKAGE_NAME}" '$1 != root {exit 1}'; then
  fail "ZIP contains a path outside ${PACKAGE_NAME}/"
fi

mv -f -- "${TEMP_ZIP}" "${OUTPUT_ZIP}"
if command -v sha256sum >/dev/null 2>&1; then
  readonly CHECKSUM="$(sha256sum "${OUTPUT_ZIP}" | awk '{print $1}')"
else
  readonly CHECKSUM="$(shasum -a 256 "${OUTPUT_ZIP}" | awk '{print $1}')"
fi

printf 'Artifact: %s\n' "${OUTPUT_ZIP}"
printf 'SHA-256: %s\n' "${CHECKSUM}"
