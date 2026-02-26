<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use App\Models\Pedido;

Route::get('/__ping', fn () => 'OK WEB '.app()->version());

Route::get('/test-mail-all', function () {
    $pedido = Pedido::with(['items', 'envio', 'pagos', 'sucursal', 'cliente'])
        ->orderByDesc('id')->first();

    $resultados = [];
    $clienteEmail = $pedido->email_contacto;

    // 1. PedidoConfirmadoCliente (mailer: pedidos → cliente)
    try {
        Mail::mailer('pedidos')
            ->to($clienteEmail)
            ->send(new \App\Mail\PedidoConfirmadoCliente($pedido));
        $resultados['PedidoConfirmadoCliente'] = 'OK - enviado a ' . $clienteEmail;
    } catch (\Throwable $e) {
        $resultados['PedidoConfirmadoCliente'] = 'ERROR - ' . $e->getMessage();
    }

    // 2. PedidoConfirmadoAdmin (mailer: pedidos → pedidos@ferrear.com.ar)
    try {
        Mail::mailer('pedidos')
            ->to('pedidos@ferrear.com.ar')
            ->send(new \App\Mail\PedidoConfirmadoAdmin($pedido));
        $resultados['PedidoConfirmadoAdmin'] = 'OK - enviado a pedidos@ferrear.com.ar';
    } catch (\Throwable $e) {
        $resultados['PedidoConfirmadoAdmin'] = 'ERROR - ' . $e->getMessage();
    }

    // 3. ContactoMail (mailer: contacto → ferreteriaargrw@gmail.com)
    try {
        Mail::mailer('contacto')
            ->to('ferreteriaargrw@gmail.com')
            ->send(new \App\Mail\ContactoMail([
                'nombre' => 'Test Usuario',
                'email' => 'test@ejemplo.com',
                'telefono' => '1122334455',
                'asunto' => 'Consulta de prueba',
                'mensaje' => 'Este es un mensaje de prueba del formulario de contacto.',
            ]));
        $resultados['ContactoMail'] = 'OK - enviado a ferreteriaargrw@gmail.com';
    } catch (\Throwable $e) {
        $resultados['ContactoMail'] = 'ERROR - ' . $e->getMessage();
    }

    // 4. VerifyEmailOtpMail (mailer: verificaciones → cliente/usuario)
    try {
        Mail::mailer('verificaciones')
            ->to($clienteEmail)
            ->send(new \App\Mail\VerifyEmailOtpMail('123456', $pedido->nombre_contacto ?? 'Test Usuario'));
        $resultados['VerifyEmailOtpMail'] = 'OK - enviado a ' . $clienteEmail;
    } catch (\Throwable $e) {
        $resultados['VerifyEmailOtpMail'] = 'ERROR - ' . $e->getMessage();
    }

    // 5. PostulacionMail (mailer: rrhh → rrhh@ferrear.com.ar)
    try {
        \Illuminate\Support\Facades\Storage::put('postulaciones/test.pdf', 'CV de prueba');
        Mail::mailer('rrhh')
            ->to('rrhh@ferrear.com.ar')
            ->send(new \App\Mail\PostulacionMail([
                'nombre' => 'Test Postulante',
                'email' => 'postulante@ejemplo.com',
                'telefono' => '1155667788',
                'puesto' => 'Vendedor',
                'mensaje' => 'Me interesa el puesto.',
            ], 'postulaciones/test.pdf'));
        \Illuminate\Support\Facades\Storage::delete('postulaciones/test.pdf');
        $resultados['PostulacionMail'] = 'OK - enviado a rrhh@ferrear.com.ar';
    } catch (\Throwable $e) {
        $resultados['PostulacionMail'] = 'ERROR - ' . $e->getMessage();
    }

    // NOTA: PedidoCreadoMail y PagoAprobadoMail no se testean (templates placeholder)

    return response()->json($resultados, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
});
