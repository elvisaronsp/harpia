<?php

namespace Modulos\Seguranca\Providers\Seguranca;

use Illuminate\Contracts\Foundation\Application;
use Modulos\Seguranca\Providers\Seguranca\Contracts\Seguranca as SegurancaContract;
use Modulos\Seguranca\Providers\Seguranca\Exceptions\ForbiddenException;
use Cache;
use Illuminate\Support\Facades\DB;

class Seguranca implements SegurancaContract
{
    /**
     * The Laravel Application.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }
 
    /**
     * Retorna o usuário logado na aplicação
     */
    public function getUser()
    {
        return $this->app['auth']->user();
    }

    public function makeCacheMenu()
    {
        $usrId = $this->getUser()->getAuthIdentifier();

        $categoriasModulos =  DB::table('seg_perfis_usuarios')
            ->join('seg_perfis', 'pru_prf_id', '=', 'prf_id')
            ->join('seg_modulos', 'prf_mod_id', '=', 'mod_id')
            ->join(DB::raw('seg_categorias_recursos AS cr'), 'ctr_mod_id', '=', 'mod_id')
            ->select(DB::raw('mod_id, mod_rota, cr.*'))
            ->where('pru_usr_id', '=', $usrId)
            ->where('mod_ativo', '=', true)
            ->where('ctr_visivel', '=', true)
            ->orderBy('mod_rota', 'asc')
            ->orderBy('ctr_referencia', 'asc')
            ->orderBy('ctr_id', 'asc')
            ->orderBy('ctr_ordem', 'asc')
            ->get();

        $arrayMenu = [];
        $pai = 0;

        for ($i=0; $i < count($categoriasModulos); $i++) {
            if (!array_key_exists($categoriasModulos[$i]->mod_rota, $arrayMenu)) {
                $arrayMenu[$categoriasModulos[$i]->mod_rota] = array(
                    'mod_id' => $categoriasModulos[$i]->mod_rota,
                    'mod_rota' => $categoriasModulos[$i]->mod_rota,
                    'CATEGORIAS' => array()
                );
            }

            if (is_null($categoriasModulos[$i]->ctr_referencia)) {
                $arrayMenu[$categoriasModulos[$i]->mod_rota]['CATEGORIAS'][$categoriasModulos[$i]->ctr_id] = array(
                    'ctr_id' => $categoriasModulos[$i]->ctr_id,
                    'ctr_nome' => $categoriasModulos[$i]->ctr_nome,
                    'ctr_icone' => $categoriasModulos[$i]->ctr_icone,
                    'ITENS' => array()
                );
            }

            if (!is_null($categoriasModulos[$i]->ctr_referencia)) {
                $arrayMenu[$categoriasModulos[$i]->mod_rota]['CATEGORIAS'][$categoriasModulos[$i]->ctr_referencia]['SUBCATEGORIA'][$categoriasModulos[$i]->ctr_id] = array(
                    'ctr_id' => $categoriasModulos[$i]->ctr_id,
                    'ctr_nome' => $categoriasModulos[$i]->ctr_nome,
                    'ctr_icone' => $categoriasModulos[$i]->ctr_icone,
                    'ITENS' => array()
                );
            }
        }

        $recursos = DB::table('seg_perfis_usuarios')
            ->select('mod_id','mod_rota','ctr_id', 'ctr_nome', 'ctr_referencia', 'rcs_id','rcs_nome','rcs_rota','rcs_descricao','rcs_icone','prm_nome')
            ->join('seg_perfis_permissoes', 'prp_prf_id', '=', 'pru_prf_id')
            ->join('seg_permissoes', function ($join) {
                $join->on('prp_prm_id', '=', 'prm_id')
                    ->where('prm_nome', '=', 'index');
            })
            ->join('seg_recursos', 'prm_rcs_id', '=', 'rcs_id')
            ->join('seg_categorias_recursos', 'rcs_ctr_id', '=', 'ctr_id')
            ->join('seg_modulos', 'ctr_mod_id', '=', 'mod_id')
            ->where('rcs_ativo', '=', true)
            ->where('ctr_ativo', '=', true)
            ->where('pru_usr_id', '=', $usrId)
            ->orderBy('mod_id','asc')
            ->orderBy('ctr_id','asc')
            ->orderBy('rcs_ordem', 'asc')
            ->get();

        foreach ($recursos as $key => $recurso) {
            if (!array_key_exists($recurso->ctr_id, $arrayMenu[$recurso->mod_rota]['CATEGORIAS'])) {
                if (array_key_exists($recurso->ctr_id, $arrayMenu[$recurso->mod_rota]['CATEGORIAS'][$recurso->ctr_referencia]['SUBCATEGORIA'])) {
                    $arrayMenu[$recurso->mod_rota]['CATEGORIAS'][$recurso->ctr_referencia]['SUBCATEGORIA'][$recurso->ctr_id]['ITENS'][$recurso->rcs_id] = array(
                        'rcs_id' => $recurso->rcs_id,
                        'rcs_nome' => $recurso->rcs_nome,
                        'rcs_rota' => $recurso->rcs_rota,
                        'rcs_icone' => $recurso->rcs_icone,
                        'prm_nome' => $recurso->prm_nome
                    );
                }
            }

            if (array_key_exists($recurso->ctr_id, $arrayMenu[$recurso->mod_rota]['CATEGORIAS'])) {
                $arrayMenu[$recurso->mod_rota]['CATEGORIAS'][$recurso->ctr_id]['ITENS'][$recurso->rcs_id] = array(
                    'rcs_id' => $recurso->rcs_id,
                    'rcs_nome' => $recurso->rcs_nome,
                    'rcs_rota' => $recurso->rcs_rota,
                    'rcs_icone' => $recurso->rcs_icone,
                    'prm_nome' => $recurso->prm_nome
                );
            }
        }

        //Estrutura do menu em cache
        Cache::forever('MENU_'.$usrId, $arrayMenu);
    }

    public function makeCachePermission()
    {
        $usrId = $this->getUser()->getAuthIdentifier();

        $sql = 'SELECT 
                    mod_id,mod_rota,mod_nome,mod_descricao,mod_icone,mod_class,rcs_nome,rcs_rota,prm_nome
                FROM
                    seg_perfis_usuarios
                    INNER JOIN seg_perfis ON pru_prf_id = prf_id
                    INNER JOIN seg_perfis_permissoes ON prp_prf_id = prf_id
                    INNER JOIN seg_permissoes ON prp_prm_id = prm_id
                    INNER JOIN seg_modulos ON prf_mod_id = mod_id
                    INNER JOIN seg_recursos ON prm_rcs_id = rcs_id
                WHERE
                    pru_usr_id = :usrId
                    AND rcs_ativo = true AND mod_ativo = true';
        
        $permissoes =  DB::select($sql, ['usrId' => $usrId]);

        //Estrutura de permissão em cache
        Cache::forever('PERMISSAO_'.$usrId, $permissoes);
    }

    /**
     * Verifica se o usuário tem acesso ao recurso
     *
     * @param string|array $permissao
     * @return bool
     * @throws ForbiddenException
     */
    public function haspermission($path)
    {
        list($modulo, $recurso, $permissao) = $this->extractPathResources($path);

        // O usuario nao esta logado, porem a rota eh liberada para usuarios guest.
        if (is_null($this->getUser())) {
            if ($this->isPreLoginOpenActions($modulo, $recurso, $permissao)) {
                return true;
            }

            return false;
        }

        // Verifica se a rota eh liberada pas usuarios logados.
        if ($this->isPostLoginOpenActions($modulo, $recurso, $permissao)) {
            return true;
        }

        // Verifica na base de dados se o perfil do usuario tem acesso ao recurso
        $hasPermission = $this->verifyPermission($this->getUser()->getAuthIdentifier(), $modulo, $recurso, $permissao);

        if ($hasPermission) {
            return true;
        }

        return false;
    }

    /**
     * Verifica se a rota eh liberada para usuarios que nao estao logados no sistema
     *
     * @param $modulo
     * @param $recurso
     * @param $permissao
     * @return bool
     */
    private function isPreLoginOpenActions($modulo, $recurso, $permissao)
    {
        $fullRoute = $modulo . '/' . $recurso . '/' . $permissao;

        $openActions = $this->app['config']->get('seguranca.prelogin_openactions', []);

        return in_array($fullRoute, $openActions);
    }

    /**
     * Verifica se a rota eh liberada para usuarios que estao logados no sistema
     *
     * @param $modulo
     * @param $recurso
     * @param $permissao
     * @return bool
     */
    private function isPostLoginOpenActions($modulo, $recurso, $permissao)
    {
        $fullRoute = $modulo . '/' . $recurso . '/' . $permissao;

        $openActions = $this->app['config']->get('seguranca.postlogin_openactions', []);

        return in_array($fullRoute, $openActions);
    }

    public function getUserModules()
    {
        $usrId = $this->getUser()->getAuthIdentifier();

        $permissoes = Cache::get('PERMISSAO_'.$usrId);

        $modulos = [];

        if (empty($permissoes)) {
            return $modulos;
        }

        foreach ($permissoes as $key => $permissao) {
            $modulos[$permissao->mod_id] = array(
                'mod_id' => $permissao->mod_id,
                'mod_rota' => $permissao->mod_rota,
                'mod_nome' => $permissao->mod_nome,
                'mod_descricao' => $permissao->mod_descricao,
                'mod_icone' => $permissao->mod_icone,
                'mod_class' => $permissao->mod_class,
            );
        }

        return $modulos;
    }

    /**
     * Verifica se o usuario tem acesso ao recurso.
     *
     * @param int    $usr_id
     * @param stirng $mod_rota
     * @param string $rcs_nome
     * @param string $prm_nome
     *
     * @return mixed
     */
    private function verifyPermission($usr_id, $mod_rota, $rcs_rota, $prm_nome)
    {
        $permissoes = Cache::get('PERMISSAO_'.$usr_id);

        foreach ($permissoes as $key => $permissao) {
            if (mb_strtolower($permissao->mod_rota) == mb_strtolower($mod_rota) &&
                mb_strtolower($permissao->rcs_rota) == mb_strtolower($rcs_rota) &&
                mb_strtolower($permissao->prm_nome) == mb_strtolower($prm_nome)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gera um array com as partes da url -> modulo / recurso / permissao
     *
     * @param $fullPath
     * @return array
     */
    private function extractPathResources($fullPath)
    {
        if (is_string($fullPath)) {
            $fullPath = explode("/", $fullPath);
        }

        $pathArray[0] = isset($fullPath[0]) ? $fullPath[0] : 'index';
        $pathArray[1] = isset($fullPath[1]) ? $fullPath[1] : 'index';
        $pathArray[2] = isset($fullPath[2]) ? $fullPath[2] : 'index';

        return $pathArray;
    }
}
