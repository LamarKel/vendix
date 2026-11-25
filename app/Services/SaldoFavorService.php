<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TipoMovimientoSaldo;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\MovimientoSaldo;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;

/**
 * Gestiona el saldo a favor del cliente: depósitos (vuelto no devuelto),
 * consumos (pago con saldo) y devoluciones (retiro en efectivo).
 *
 * Toda operación bloquea la fila del cliente con lockForUpdate para evitar
 * race conditions y registra un movimiento con saldo anterior/posterior.
 * El llamador DEBE envolverlo en DB::transaction() cuando forma parte de un
 * cobro (VentaService ya lo hace).
 */
class SaldoFavorService
{
    /**
     * Deposita un monto al saldo a favor del cliente (vuelto que se deja como fondo).
     *
     * @param  string|float  $monto  Monto positivo a depositar
     * @param  string|null  $concepto  Descripción opcional del movimiento
     */
    public function depositar(
        Cliente $cliente,
        Empresa $empresa,
        string|float $monto,
        ?Venta $venta = null,
        ?int $userId = null,
        ?string $concepto = null,
    ): MovimientoSaldo {
        $monto = (string) $monto;
        $this->validarMontoPositivo($monto);
        $this->validarClienteDeEmpresa($cliente, $empresa);

        $cliente = Cliente::lockForUpdate()->findOrFail($cliente->id);

        $saldoAnterior = (string) $cliente->saldo_favor;
        $saldoPosterior = bcadd($saldoAnterior, $monto, 2);

        $cliente->update(['saldo_favor' => $saldoPosterior]);

        return MovimientoSaldo::create([
            'empresa_id' => $empresa->id,
            'cliente_id' => $cliente->id,
            'tipo' => TipoMovimientoSaldo::DEPOSITO,
            'monto' => $monto,
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior' => $saldoPosterior,
            'concepto' => $concepto ?? 'Vuelto dejado como saldo a favor',
            'venta_id' => $venta?->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Consume saldo a favor del cliente para pagar (total o parcialmente).
     *
     * @param  string|float  $monto  Monto positivo a descontar del saldo
     *
     * @throws \RuntimeException Si el saldo es insuficiente
     */
    public function consumir(
        Cliente $cliente,
        Empresa $empresa,
        string|float $monto,
        ?Venta $venta = null,
        ?int $userId = null,
        ?string $concepto = null,
    ): MovimientoSaldo {
        $monto = (string) $monto;
        $this->validarMontoPositivo($monto);
        $this->validarClienteDeEmpresa($cliente, $empresa);

        $cliente = Cliente::lockForUpdate()->findOrFail($cliente->id);

        $saldoAnterior = (string) $cliente->saldo_favor;

        if (bccomp($saldoAnterior, $monto, 2) < 0) {
            throw new \RuntimeException(
                'Saldo insuficiente. Disponible: RD$ '.number_format((float) $saldoAnterior, 2)
            );
        }

        $saldoPosterior = bcsub($saldoAnterior, $monto, 2);

        $cliente->update(['saldo_favor' => $saldoPosterior]);

        return MovimientoSaldo::create([
            'empresa_id' => $empresa->id,
            'cliente_id' => $cliente->id,
            'tipo' => TipoMovimientoSaldo::CONSUMO,
            'monto' => $monto,
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior' => $saldoPosterior,
            'concepto' => $concepto ?? 'Pago con saldo a favor',
            'venta_id' => $venta?->id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Devuelve en efectivo (total o parcial) el saldo a favor del cliente.
     *
     * @param  string|float  $monto  Monto positivo a devolver
     *
     * @throws \RuntimeException Si el saldo es insuficiente
     */
    public function devolver(
        Cliente $cliente,
        Empresa $empresa,
        string|float $monto,
        ?int $userId = null,
        ?string $concepto = null,
    ): MovimientoSaldo {
        $monto = (string) $monto;
        $this->validarMontoPositivo($monto);
        $this->validarClienteDeEmpresa($cliente, $empresa);

        $cliente = Cliente::lockForUpdate()->findOrFail($cliente->id);

        $saldoAnterior = (string) $cliente->saldo_favor;

        if (bccomp($saldoAnterior, $monto, 2) < 0) {
            throw new \RuntimeException(
                'No se puede devolver más del saldo disponible: RD$ '.number_format((float) $saldoAnterior, 2)
            );
        }

        $saldoPosterior = bcsub($saldoAnterior, $monto, 2);

        $cliente->update(['saldo_favor' => $saldoPosterior]);

        return MovimientoSaldo::create([
            'empresa_id' => $empresa->id,
            'cliente_id' => $cliente->id,
            'tipo' => TipoMovimientoSaldo::DEVOLUCION,
            'monto' => $monto,
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior' => $saldoPosterior,
            'concepto' => $concepto ?? 'Devolución de saldo a favor en efectivo',
            'user_id' => $userId,
        ]);
    }

    private function validarMontoPositivo(string $monto): void
    {
        if (bccomp($monto, '0.00', 2) <= 0) {
            throw new \InvalidArgumentException('El monto debe ser mayor que cero.');
        }
    }

    private function validarClienteDeEmpresa(Cliente $cliente, Empresa $empresa): void
    {
        if ((int) $cliente->empresa_id !== (int) $empresa->id) {
            throw new \RuntimeException('El cliente no pertenece a la empresa indicada.');
        }
    }
}
