(function (angular) {
  'use strict';

  angular.module('afSumUp').component('afSumUpReaders', {
    templateUrl: '~/afSumUp/sumUpReaders.html',
    controller: function ($scope) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');

      this.$onInit = () => {
        this.processors = [];
        this.selectedProcessorId = null;
        this.pairedReaders = [];
        this.discoveredReaders = [];
        this.loading = true;
        this.syncing = false;
        this.error = '';
        this.statusMessage = '';

        this.pairing = {
          siteCode: '',
          pairingCode: '',
          submitting: false,
          error: '',
          success: '',
        };

        this.adoptModal = {
          open: false,
          reader: null,
          siteCode: '',
          submitting: false,
          error: '',
        };

        this.reassignModal = {
          open: false,
          reader: null,
          siteCode: '',
          submitting: false,
          error: '',
        };

        this.loadProcessors();
      };

      this.loadProcessors = () => {
        CRM.api4('PaymentProcessor', 'get', {
          where: [['class_name', '=', 'Payment_Sumup'], ['is_active', '=', true]],
          select: ['id', 'title', 'name', 'user_name', 'is_test'],
        }).then((processors) => {
          this.processors = processors;
          if (processors.length > 0) {
            var urlParams = new URLSearchParams(window.location.search);
            var queryProcessor = parseInt(urlParams.get('pp'), 10);
            var matched = processors.find((p) => p.id === queryProcessor);
            this.selectedProcessorId = matched ? matched.id : processors[0].id;
            this.loadReaders();
          } else {
            this.loading = false;
            this.error = ts('No active SumUp payment processor found. Please configure a processor first.');
          }
        }).catch((err) => {
          this.loading = false;
          this.error = err.error_message || ts('Unable to load payment processors.');
        }).finally(() => {
          $scope.$applyAsync();
        });
      };

      this.onProcessorChange = () => {
        this.statusMessage = '';
        this.pairing.error = '';
        this.pairing.success = '';
        this.loadReaders();
      };

      this.loadReaders = () => {
        if (!this.selectedProcessorId) {
          return;
        }
        this.loading = true;
        this.error = '';

        Promise.all([
          CRM.api4('SumupReader', 'get', {
            where: [['payment_processor_id', '=', this.selectedProcessorId]],
            orderBy: {site_code: 'ASC', is_active: 'DESC', canonical_name: 'ASC'},
          }),
          CRM.api4('SumupReader', 'listDiscovered', {
            paymentProcessorId: this.selectedProcessorId,
          }),
        ]).then(([paired, discovered]) => {
          this.pairedReaders = paired;
          this.discoveredReaders = discovered;
        }).catch((err) => {
          this.error = err.error_message || ts('Unable to load SumUp readers.');
        }).finally(() => {
          this.loading = false;
          $scope.$applyAsync();
        });
      };

      this.sync = () => {
        if (!this.selectedProcessorId || this.syncing) {
          return;
        }
        this.syncing = true;
        this.statusMessage = '';
        this.error = '';

        CRM.api4('SumupReader', 'synchronise', {
          paymentProcessorId: this.selectedProcessorId,
        }).then(() => {
          this.statusMessage = ts('Fleet synchronised successfully with SumUp Cloud API.');
          this.loadReaders();
        }).catch((err) => {
          this.error = err.error_message || ts('Synchronisation failed.');
        }).finally(() => {
          this.syncing = false;
          $scope.$applyAsync();
        });
      };

      this.pairReader = () => {
        var siteCode = (this.pairing.siteCode || '').trim().toUpperCase();
        var pairingCode = (this.pairing.pairingCode || '').trim().toUpperCase();

        this.pairing.error = '';
        this.pairing.success = '';

        if (!/^[A-Z0-9]{2,12}$/.test(siteCode)) {
          this.pairing.error = ts('Site code must be 2 to 12 alphanumeric characters (e.g. BAR, ACCUEIL).');
          return;
        }
        if (!/^[A-Z0-9]{8,9}$/.test(pairingCode)) {
          this.pairing.error = ts('Pairing code must be 8 or 9 alphanumeric characters displayed on the Solo screen.');
          return;
        }

        this.pairing.submitting = true;
        CRM.api4('SumupReader', 'pair', {
          paymentProcessorId: this.selectedProcessorId,
          siteCode: siteCode,
          pairingCode: pairingCode,
        }).then((res) => {
          var reader = res[0] || {};
          this.pairing.success = ts('Card reader %1 successfully paired with site %2.', {
            1: reader.canonical_name || reader.name || pairingCode,
            2: siteCode,
          });
          this.pairing.pairingCode = '';
          this.loadReaders();
        }).catch((err) => {
          this.pairing.error = err.error_message || ts('Failed to pair the SumUp card reader.');
        }).finally(() => {
          this.pairing.submitting = false;
          $scope.$applyAsync();
        });
      };

      this.openAdoptModal = (discovered) => {
        this.adoptModal.reader = discovered;
        this.adoptModal.siteCode = discovered.site_code || '';
        this.adoptModal.error = '';
        this.adoptModal.open = true;
      };

      this.closeAdoptModal = () => {
        this.adoptModal.open = false;
        this.adoptModal.reader = null;
        this.adoptModal.error = '';
      };

      this.adoptReader = () => {
        var siteCode = (this.adoptModal.siteCode || '').trim().toUpperCase();
        if (!/^[A-Z0-9]{2,12}$/.test(siteCode)) {
          this.adoptModal.error = ts('Site code must be 2 to 12 alphanumeric characters (e.g. BAR).');
          return;
        }

        this.adoptModal.submitting = true;
        this.adoptModal.error = '';

        CRM.api4('SumupReader', 'adopt', {
          paymentProcessorId: this.selectedProcessorId,
          readerId: this.adoptModal.reader.reader_id,
          siteCode: siteCode,
        }).then(() => {
          this.statusMessage = ts('Terminal adopted successfully for site %1.', {1: siteCode});
          this.closeAdoptModal();
          this.loadReaders();
        }).catch((err) => {
          this.adoptModal.error = err.error_message || ts('Failed to adopt the terminal.');
        }).finally(() => {
          this.adoptModal.submitting = false;
          $scope.$applyAsync();
        });
      };

      this.openReassignModal = (reader) => {
        this.reassignModal.reader = reader;
        this.reassignModal.siteCode = reader.site_code || '';
        this.reassignModal.error = '';
        this.reassignModal.open = true;
      };

      this.closeReassignModal = () => {
        this.reassignModal.open = false;
        this.reassignModal.reader = null;
        this.reassignModal.error = '';
      };

      this.submitReassign = () => {
        var siteCode = (this.reassignModal.siteCode || '').trim().toUpperCase();
        if (!/^[A-Z0-9]{2,12}$/.test(siteCode)) {
          this.reassignModal.error = ts('Site code must be 2 to 12 alphanumeric characters (e.g. BAR, ACCUEIL).');
          return;
        }

        this.reassignModal.submitting = true;
        this.reassignModal.error = '';

        CRM.api4('SumupReader', 'reassignSite', {
          id: this.reassignModal.reader.id || 0,
          paymentProcessorId: this.selectedProcessorId,
          readerId: this.reassignModal.reader.reader_id,
          siteCode: siteCode,
        }).then(() => {
          this.statusMessage = ts('Terminal reassigned successfully to site %1.', {1: siteCode});
          this.closeReassignModal();
          this.loadReaders();
        }).catch((err) => {
          this.reassignModal.error = err.error_message || ts('Failed to reassign terminal site.');
        }).finally(() => {
          this.reassignModal.submitting = false;
          $scope.$applyAsync();
        });
      };

      this.unpairReader = (reader, deleteRecord) => {
        var name = reader.canonical_name || reader.name || reader.reader_id;
        var confirmMsg = deleteRecord
          ? ts('Permanently delete terminal %1 from SumUp Cloud API and CiviCRM?', {1: name})
          : ts('Disconnect terminal %1 from SumUp Cloud API? It will be marked inactive in CiviCRM.', {1: name});

        if (!window.confirm(confirmMsg)) {
          return;
        }

        this.loading = true;
        this.statusMessage = '';
        this.error = '';

        CRM.api4('SumupReader', 'unpair', {
          id: reader.id || 0,
          paymentProcessorId: this.selectedProcessorId,
          readerId: reader.reader_id,
          deleteLocal: !!deleteRecord,
        }).then(() => {
          this.statusMessage = ts('Terminal %1 disconnected successfully.', {1: name});
          this.loadReaders();
        }).catch((err) => {
          this.error = err.error_message || ts('Failed to disconnect terminal.');
          this.loading = false;
        }).finally(() => {
          $scope.$applyAsync();
        });
      };
    },
  });
}(angular));
