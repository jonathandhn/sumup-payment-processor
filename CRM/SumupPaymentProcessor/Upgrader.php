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
        $this->ensureRemediationTable();
        $this->ensureTokenCustomerTable();
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

    public function upgrade_1004(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1004: retain the Solo reader on checkout attempts.');
        $this->ensureCheckoutTable();
        $column = CRM_Core_DAO::executeQuery(
            'SHOW COLUMNS FROM civicrm_sumup_checkout LIKE %1',
            [1 => ['reader_id', 'String']]
        );
        if (!$column->fetch()) {
            CRM_Core_DAO::executeQuery(
                'ALTER TABLE civicrm_sumup_checkout
                 ADD COLUMN reader_id varchar(100) NULL DEFAULT NULL AFTER transaction_id'
            );
        }

        return true;
    }

    public function upgrade_1005(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1005: remove the obsolete Solo test collection menu item.');
        \Civi\Api4\Navigation::delete(false)
            ->addWhere('name', '=', 'Collect with SumUp Solo (test)')
            ->execute();
        CRM_Core_DAO::executeQuery(
            'DELETE FROM civicrm_menu WHERE path = %1',
            [1 => ['civicrm/admin/setting/sumup_payment_processor', 'String']]
        );

        return true;
    }

    public function upgrade_1006(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1006: add recurring-card checkout metadata.');
        CRM_Core_DAO::executeQuery(
            "UPDATE civicrm_payment_processor_type SET is_recur = 1 WHERE name = 'SumUp'"
        );
        $this->ensureCheckoutTable();
        $columns = [
            'purpose' => "purpose varchar(32) NOT NULL DEFAULT 'PAYMENT' AFTER reader_id",
            'customer_id' => 'customer_id varchar(100) NULL DEFAULT NULL AFTER purpose',
            'payment_token_id' => 'payment_token_id int unsigned NULL DEFAULT NULL AFTER customer_id',
            'setup_checkout_id' => 'setup_checkout_id varchar(100) NULL DEFAULT NULL AFTER payment_token_id',
        ];
        foreach ($columns as $name => $definition) {
            $column = CRM_Core_DAO::executeQuery(
                'SHOW COLUMNS FROM civicrm_sumup_checkout LIKE %1',
                [1 => [$name, 'String']]
            );
            if (!$column->fetch()) {
                CRM_Core_DAO::executeQuery("ALTER TABLE civicrm_sumup_checkout ADD COLUMN {$definition}");
            }
        }
        $index = CRM_Core_DAO::executeQuery(
            "SHOW INDEX FROM civicrm_sumup_checkout WHERE Key_name = 'unique_sumup_setup_charge'"
        );
        if (!$index->fetch()) {
            CRM_Core_DAO::executeQuery(
                'ALTER TABLE civicrm_sumup_checkout ADD UNIQUE KEY unique_sumup_setup_charge (setup_checkout_id)'
            );
        }

        return true;
    }

    public function upgrade_1007(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1007: enable recurrence on existing processor instances.');
        CRM_Core_DAO::executeQuery(
            "UPDATE civicrm_payment_processor SET is_recur = 1 WHERE class_name = 'Payment_Sumup'"
        );

        return true;
    }

    public function upgrade_1008(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1008: add recurring-card remediation registry.');
        $this->ensureRemediationTable();

        return true;
    }

    public function upgrade_1009(): bool
    {
        $this->ctx->log->info('Applying SumUp update 1009: retain the remote customer for each saved card.');
        $this->ensureTokenCustomerTable();
        CRM_Core_DAO::executeQuery(
            'INSERT INTO civicrm_sumup_payment_token_customer
                (payment_token_id, customer_id, modified_date)
             SELECT checkout.payment_token_id, checkout.customer_id, NOW()
             FROM civicrm_sumup_checkout checkout
             INNER JOIN (
                 SELECT payment_token_id, MAX(id) AS id
                 FROM civicrm_sumup_checkout
                 WHERE payment_token_id IS NOT NULL
                   AND customer_id IS NOT NULL
                   AND customer_id <> \'\'
                 GROUP BY payment_token_id
             ) latest ON latest.id = checkout.id
             ON DUPLICATE KEY UPDATE
                customer_id = VALUES(customer_id),
                modified_date = VALUES(modified_date)'
        );

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
                reader_id varchar(100) NULL DEFAULT NULL,
                purpose varchar(32) NOT NULL DEFAULT \'PAYMENT\',
                customer_id varchar(100) NULL DEFAULT NULL,
                payment_token_id int unsigned NULL DEFAULT NULL,
                setup_checkout_id varchar(100) NULL DEFAULT NULL,
                created_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                modified_date datetime NULL DEFAULT NULL,
                verified_date datetime NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY unique_sumup_checkout_id (checkout_id),
                UNIQUE KEY unique_sumup_checkout_reference (checkout_reference),
                KEY index_sumup_contribution (contribution_id),
                KEY index_sumup_processor_state (payment_processor_id, state),
                UNIQUE KEY unique_sumup_setup_charge (setup_checkout_id),
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

    private function ensureRemediationTable(): void
    {
        CRM_Core_DAO::executeQuery(
            'CREATE TABLE IF NOT EXISTS civicrm_sumup_remediation (
                id int unsigned NOT NULL AUTO_INCREMENT,
                contribution_recur_id int unsigned NOT NULL,
                contribution_id int unsigned NOT NULL,
                payment_processor_id int unsigned NOT NULL,
                checkout_id varchar(100) NULL DEFAULT NULL,
                payment_token_id int unsigned NULL DEFAULT NULL,
                replacement_checkout_id varchar(100) NULL DEFAULT NULL,
                replacement_payment_token_id int unsigned NULL DEFAULT NULL,
                reason varchar(32) NOT NULL,
                provider_error_code varchar(100) NULL DEFAULT NULL,
                state varchar(32) NOT NULL DEFAULT \'customer_action_required\',
                created_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                modified_date datetime NULL DEFAULT NULL,
                resolved_date datetime NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY index_sumup_remediation_recur_state (contribution_recur_id, state),
                KEY index_sumup_remediation_contribution (contribution_id),
                CONSTRAINT FK_sumup_remediation_recur
                  FOREIGN KEY (contribution_recur_id) REFERENCES civicrm_contribution_recur(id) ON DELETE CASCADE,
                CONSTRAINT FK_sumup_remediation_contribution
                  FOREIGN KEY (contribution_id) REFERENCES civicrm_contribution(id) ON DELETE CASCADE,
                CONSTRAINT FK_sumup_remediation_processor
                  FOREIGN KEY (payment_processor_id) REFERENCES civicrm_payment_processor(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }

    private function ensureTokenCustomerTable(): void
    {
        CRM_Core_DAO::executeQuery(
            'CREATE TABLE IF NOT EXISTS civicrm_sumup_payment_token_customer (
                payment_token_id int unsigned NOT NULL,
                customer_id varchar(100) NOT NULL,
                created_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                modified_date datetime NULL DEFAULT NULL,
                PRIMARY KEY (payment_token_id),
                KEY index_sumup_token_customer (customer_id),
                CONSTRAINT FK_sumup_token_customer_payment_token
                  FOREIGN KEY (payment_token_id) REFERENCES civicrm_payment_token(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }
}
