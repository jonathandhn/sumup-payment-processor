<?php

declare(strict_types=1);

namespace Civi\Api4\Action\SumupPaymentMethod;

use Civi\Api4\ContributionRecur;
use Civi\Api4\Generic\Result;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\SumupPaymentProcessor\WorkflowMessage\CardChangeInvitation;
use Civi\SumupPaymentProcessor\WorkflowMessage\PlanChangeInvitation;
use CRM_SumupPaymentProcessor_ExtensionUtil as E;

/**
 * @method $this setRecurId(int $recurId)
 * @method $this setLinkType(string $linkType)
 */
final class SendManagementLink extends ActionBase
{
    protected int $recurId = 0;

    protected string $linkType = '';

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore
    public function _run(Result $result): void
    {
        if (!$this->isAdministrativeRequest()) {
            throw new PaymentProcessorException(E::ts('Only a CiviCRM administrator may send this link.'));
        }
        if (!in_array($this->linkType, ['card', 'plan'], true)) {
            throw new PaymentProcessorException(E::ts('Invalid SumUp management-link type.'));
        }

        $schedule = $this->ownedSchedule($this->recurId);
        $contactId = (int) $schedule['contact_id'];
        [$displayName, $email] = \CRM_Contact_BAO_Contact::getContactDetails($contactId);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PaymentProcessorException(E::ts('This contact has no valid email address.'));
        }

        $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactId);
        $url = $this->managementUrl($contactId, $checksum);
        $recur = ContributionRecur::get(false)
            ->addSelect('*')
            ->addWhere('id', '=', $this->recurId)
            ->execute()
            ->single();
        $contact = [
            'id' => $contactId,
            'contact_id' => $contactId,
            'display_name' => $displayName,
            'email_primary.email' => $email,
            'email_greeting_display' => (string) \CRM_Core_DAO::getFieldValue(
                'CRM_Contact_DAO_Contact',
                $contactId,
                'email_greeting_display'
            ),
        ];

        $message = $this->linkType === 'card'
            ? new CardChangeInvitation()
            : new PlanChangeInvitation();
        $message->setContact($contact);
        $message->setContributionRecur($recur);
        $message->setManagementUrl($url);

        [$fromName, $fromEmail] = \CRM_Core_BAO_Domain::getNameAndEmail(true);
        $from = sprintf('"%s" <%s>', $fromName, $fromEmail);
        [$sent, $subject, , , $errorMessage] = $message->sendTemplate([
            'contactId' => $contactId,
            'from' => $from,
            'toName' => $displayName,
            'toEmail' => $email,
        ]);
        if (!$sent) {
            throw new PaymentProcessorException(
                E::ts('The SumUp management link could not be sent: %1', [1 => (string) $errorMessage])
            );
        }

        \Civi::log()->info(sprintf(
            'SumUp %s management link sent for recurring contribution %d to contact %d.',
            $this->linkType,
            $this->recurId,
            $contactId
        ));
        $result[] = [
            'sent' => true,
            'recipient' => $email,
            'subject' => $subject,
        ];
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore

    private function managementUrl(int $contactId, string $checksum): string
    {
        if ($this->linkType === 'card') {
            return \CRM_Utils_System::url(
                'civicrm/sumup/payment-method/replace',
                ['recur_id' => $this->recurId, 'cid' => $contactId, 'cs' => $checksum],
                true,
                null,
                false,
                true
            );
        }
        return \CRM_Utils_System::url(
            'civicrm/contribute/updaterecur',
            ['reset' => 1, 'crid' => $this->recurId, 'cs' => $checksum],
            true,
            null,
            false,
            true
        );
    }
}
