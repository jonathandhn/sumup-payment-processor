<?php

declare(strict_types=1);

namespace Civi\SumupPaymentProcessor;

use Civi\Core\Event\GenericHookEvent;
use Civi\Core\Service\AutoService;
use Civi\SumupPaymentProcessor\CheckoutOption\SumUpEmbeddedCheckout;
use Civi\SumupPaymentProcessor\CheckoutOption\SumUpQrCheckout;
use Civi\SumupPaymentProcessor\CheckoutOption\SumUpSoloCheckout;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Publish SumUp CheckoutOptions for Afform / Form Builder.
 *
 * @service sumup.connections
 */
class SumUpConnections extends AutoService implements EventSubscriberInterface
{
    /**
     * @return array<string, string|array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'civi.checkout.options' => 'getCheckoutOptions',
        ];
    }

    public function getCheckoutOptions(GenericHookEvent $e): void
    {
        if (
            !interface_exists('Civi\\Checkout\\CheckoutOptionInterface')
            || !interface_exists('Civi\\Checkout\\AfformCheckoutOptionInterface')
        ) {
            return;
        }

        foreach ($this->getPaymentProcessorPairs() as $name => $pair) {
            $e->options['sumup_embedded_checkout_' . $name] = new SumUpEmbeddedCheckout(
                $pair['live'] ?? null,
                $pair['test'] ?? null
            );
            $e->options['sumup_solo_checkout_' . $name] = new SumUpSoloCheckout(
                $pair['live'] ?? null,
                $pair['test'] ?? null
            );
            $e->options['sumup_qr_checkout_' . $name] = new SumUpQrCheckout(
                $pair['live'] ?? null,
                $pair['test'] ?? null
            );
        }
    }

    /**
     * @return array<string, array{live?: array<string, mixed>, test?: array<string, mixed>}>
     */
    private function getPaymentProcessorPairs(): array
    {
        $all = \Civi\Api4\PaymentProcessor::get(false)
            ->addWhere('class_name', 'LIKE', 'Payment_Sum%')
            ->addWhere('is_active', '=', true)
            ->addWhere('is_test', 'IN', [true, false])
            ->execute();

        $pairs = [];
        foreach ($all as $processor) {
            $pairs[$processor['name']][$processor['is_test'] ? 'test' : 'live'] = $processor;
        }

        return $pairs;
    }
}
