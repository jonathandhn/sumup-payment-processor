<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * Install and upgrade SumUp operational tables.
 */
class CRM_SumupPaymentProcessor_Upgrader extends CRM_Extension_Upgrader_Base
{
    public function postInstall(): void
    {
        $this->ensureCheckoutTable();
        $this->ensureReaderTable();
    }

    public function upgrade_1001(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1001: add checkout-attempt registry.');
        $this->ensureCheckoutTable();

        return true;
    }

    public function upgrade_1002(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1002: retain the checkout mode on each attempt.');
        $this->ensureCheckoutTable();
        $column = CRM_Core_DAO::executeQuery(
            'SHOW COLUMNS FROM civicrm_sumup_checkout LIKE %1',
            [1 => ['checkout_mode', 'String']]
        );
        if (!$column->fetch()) {
            CRM_Core_DAO::executeQuery(
                "ALTER TABLE civicrm_sumup_checkout
                 ADD COLUMN checkout_mode varchar(32) NOT NULL DEFAULT 'widget' AFTER currency"
            );
        }

        return true;
    }

    public function upgrade_1003(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1003: add Solo reader registry.');
        $this->ensureReaderTable();

        return true;
    }

    private function ensureCheckoutTable(): void
    {
        CRM_Core_DAO::executeQuery(
            'CREATE TABLE IF NOT EXISTS civicrm_sumup_checkout (
                id int unsigned NOT NULL AUTO_INCREMENT,
                checkout_id varchar(100) NOT NULL,
                checkout_reference varchar(100) NOT NULL,
                contribution_id int unsigned NOT NULL,
                payment_processor_id int unsigned NOT NULL,
                state varchar(32) NOT NULL DEFAULT \'PENDING\',
                amount decimal(20,2) NOT NULL,
                currency char(3) NOT NULL,
                checkout_mode varchar(32) NOT NULL DEFAULT \'widget\',
                transaction_id varchar(100) NULL DEFAULT NULL,
                created_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                modified_date datetime NULL DEFAULT NULL,
                verified_date datetime NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_sumup_checkout_id (checkout_id),
                UNIQUE KEY unique_sumup_checkout_reference (checkout_reference),
                KEY index_sumup_contribution (contribution_id),
                KEY index_sumup_processor_state (payment_processor_id, state),
                CONSTRAINT FK_sumup_checkout_contribution
                  FOREIGN KEY (contribution_id) REFERENCES civicrm_contribution(id) ON DELETE CASCADE,
                CONSTRAINT FK_sumup_checkout_processor
                  FOREIGN KEY (payment_processor_id) REFERENCES civicrm_payment_processor(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    private function ensureReaderTable(): void
    {
        CRM_Core_DAO::executeQuery(
            'CREATE TABLE IF NOT EXISTS civicrm_sumup_reader (
                id int unsigned NOT NULL AUTO_INCREMENT,
                payment_processor_id int unsigned NOT NULL,
                reader_id varchar(100) NOT NULL,
                device_identifier varchar(100) NOT NULL,
                site_code varchar(12) NOT NULL,
                canonical_name varchar(100) NOT NULL,
                model varchar(32) NOT NULL,
                pairing_status varchar(32) NOT NULL,
                device_status varchar(32) NULL DEFAULT NULL,
                device_state varchar(32) NULL DEFAULT NULL,
                is_active tinyint(1) NOT NULL DEFAULT 1,
                created_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                modified_date datetime NULL DEFAULT NULL,
                last_sync_date datetime NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_sumup_reader_pairing (payment_processor_id, reader_id),
                KEY index_sumup_reader_device (payment_processor_id, device_identifier),
                KEY index_sumup_reader_site (payment_processor_id, site_code, is_active),
                CONSTRAINT FK_sumup_reader_processor
                  FOREIGN KEY (payment_processor_id) REFERENCES civicrm_payment_processor(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }
}
