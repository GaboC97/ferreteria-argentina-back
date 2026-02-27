<?php

namespace App\Services;

use App\Mail\ContenedorReservaConfirmadaAdmin;
use App\Mail\ContenedorReservaConfirmadaCliente;
use App\Mail\PedidoConfirmadoAdmin;
use App\Mail\PedidoConfirmadoCliente;
use App\Models\ContenedorReserva;
use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class NotificacionService
{
    /**
     * Envía mails de pedido pagado (cliente + admin) con idempotencia.
     */
    public function enviarMailsPedidoPagado(Pedido $pedido): void
    {
        $adminEmail   = config('mail.ferreteria.notif_email');
        $clienteEmail = $pedido->email_contacto;

        if (!$adminEmail && !$clienteEmail) return;

        // Mail cliente
        if ($clienteEmail) {
            $tomado = Pedido::where('id', $pedido->id)
                ->whereNull('email_cliente_enviado_at')
                ->update([
                    'email_cliente_enviado_at' => now(),
                    'mail_cliente_error_at'    => null,
                ]);

            if ($tomado) {
                try {
                    $pedidoFresh = Pedido::with(['items', 'envio', 'pagos', 'sucursal'])
                        ->find($pedido->id);

                    Mail::mailer('pedidos')
                        ->to($clienteEmail)
                        ->send(new PedidoConfirmadoCliente($pedidoFresh));
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail cliente pedido {$pedido->id}", [
                        'email' => $clienteEmail,
                        'error' => $e->getMessage(),
                    ]);

                    Pedido::where('id', $pedido->id)->update([
                        'email_cliente_enviado_at' => null,
                        'mail_cliente_error_at'    => now(),
                    ]);
                }
            }
        }

        // Mail admin
        if ($adminEmail) {
            $tomado = Pedido::where('id', $pedido->id)
                ->whereNull('email_admin_enviado_at')
                ->update([
                    'email_admin_enviado_at' => now(),
                    'mail_admin_error_at'    => null,
                ]);

            if ($tomado) {
                try {
                    $pedidoFresh = Pedido::with(['items', 'envio', 'pagos', 'sucursal', 'cliente'])
                        ->find($pedido->id);

                    Mail::mailer('pedidos')
                        ->to($adminEmail)
                        ->send(new PedidoConfirmadoAdmin($pedidoFresh));
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail admin pedido {$pedido->id}", [
                        'email' => $adminEmail,
                        'error' => $e->getMessage(),
                    ]);

                    Pedido::where('id', $pedido->id)->update([
                        'email_admin_enviado_at' => null,
                        'mail_admin_error_at'    => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Envía mails de reserva de contenedor pagada (cliente + admin) con idempotencia.
     */
    public function enviarMailsReservaContenedorPagada(Pedido $pedido, ContenedorReserva $reserva): void
    {
        $adminEmail   = config('mail.ferreteria.notif_email');
        $clienteEmail = $pedido->email_contacto;

        if (!$adminEmail && !$clienteEmail) return;

        $productoNombre = null;
        try {
            $productoNombre = Producto::find($reserva->producto_id)?->nombre;
        } catch (\Throwable $e) {
            // nombre es opcional
        }

        // Mail cliente
        if ($clienteEmail) {
            $tomado = ContenedorReserva::where('id', $reserva->id)
                ->whereNull('email_enviado_at')
                ->update([
                    'email_enviado_at'      => now(),
                    'mail_cliente_error_at' => null,
                ]);

            if ($tomado) {
                try {
                    $pedidoFresh  = Pedido::with(['items', 'envio', 'pagos', 'sucursal', 'cliente'])->find($pedido->id);
                    $reservaFresh = ContenedorReserva::find($reserva->id);

                    Mail::mailer('pedidos')
                        ->to($clienteEmail)
                        ->send(new ContenedorReservaConfirmadaCliente($pedidoFresh, $reservaFresh, $productoNombre));
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail cliente reserva contenedor {$reserva->id}", [
                        'email' => $clienteEmail,
                        'error' => $e->getMessage(),
                    ]);

                    ContenedorReserva::where('id', $reserva->id)->update([
                        'email_enviado_at'      => null,
                        'mail_cliente_error_at' => now(),
                    ]);
                }
            }
        }

        // Mail admin
        if ($adminEmail) {
            $tomado = ContenedorReserva::where('id', $reserva->id)
                ->whereNull('email_admin_enviado_at')
                ->update([
                    'email_admin_enviado_at' => now(),
                    'mail_admin_error_at'    => null,
                ]);

            if ($tomado) {
                try {
                    $pedidoFresh  = Pedido::with(['items', 'envio', 'pagos', 'sucursal', 'cliente'])->find($pedido->id);
                    $reservaFresh = ContenedorReserva::find($reserva->id);

                    Mail::mailer('pedidos')
                        ->to($adminEmail)
                        ->send(new ContenedorReservaConfirmadaAdmin($pedidoFresh, $reservaFresh, $productoNombre));
                } catch (\Throwable $e) {
                    Log::error("Error enviando mail admin reserva contenedor {$reserva->id}", [
                        'email' => $adminEmail,
                        'error' => $e->getMessage(),
                    ]);

                    ContenedorReserva::where('id', $reserva->id)->update([
                        'email_admin_enviado_at' => null,
                        'mail_admin_error_at'    => now(),
                    ]);
                }
            }
        }
    }
}
