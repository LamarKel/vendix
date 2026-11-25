<?php

namespace Tests\Feature;

use App\Enums\PerfilEmpresa;
use App\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CrearEmpresaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_la_empresa_retail_con_roles_y_modulos(): void
    {
        $this->artisan('erp:crear-empresa', [
            '--razon-social' => 'Ferretería GPO SRL',
            '--nombre-comercial' => 'Ferretería GPO',
            '--rnc' => '1-31-00000-1',
            '--perfil' => 'retail',
        ])->assertSuccessful();

        $empresa = Empresa::where('rnc', '131000001')->sole();

        $this->assertSame('ferreteria-gpo-srl', $empresa->slug);
        $this->assertSame('131000001', $empresa->rnc);
        $this->assertSame(PerfilEmpresa::RETAIL, $empresa->perfil);
        $this->assertTrue($empresa->activa);

        setPermissionsTeamId($empresa->id);
        $this->assertTrue(Role::where('empresa_id', $empresa->id)->where('name', 'Administrador')->exists());
        $this->assertGreaterThan(0, $empresa->modulos()->count());
    }

    public function test_rechaza_rnc_duplicado_y_perfil_invalido(): void
    {
        $antes = Empresa::count();

        $this->artisan('erp:crear-empresa', ['--razon-social' => 'A', '--rnc' => '131000001', '--perfil' => 'retail'])
            ->assertSuccessful();

        $this->artisan('erp:crear-empresa', ['--razon-social' => 'B', '--rnc' => '131000001', '--perfil' => 'retail'])
            ->assertFailed();

        $this->artisan('erp:crear-empresa', ['--razon-social' => 'C', '--rnc' => '', '--perfil' => 'otro'])
            ->assertFailed();

        $this->assertSame($antes + 1, Empresa::count());
    }
}
