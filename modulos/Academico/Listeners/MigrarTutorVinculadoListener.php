<?php

namespace Modulos\Academico\Listeners;

use Modulos\Academico\Events\TutorVinculadoEvent;
use Modulos\Academico\Repositories\TutorGrupoRepository;
use Modulos\Geral\Repositories\PessoaRepository;
use Modulos\Integracao\Repositories\AmbienteVirtualRepository;
use Modulos\Integracao\Events\AtualizarSyncEvent;
use Moodle;

class MigrarTutorVinculadoListener
{
    protected $pessoaRepository;
    protected $ambientVirtualRepository;
    protected $tutorGrupoRepository;

    public function __construct(PessoaRepository $pessoaRepository,
                                AmbienteVirtualRepository $ambienteVirtualRepository,
                                TutorGrupoRepository $tutorGrupoRepository)
    {
        $this->pessoaRepository = $pessoaRepository;
        $this->ambientVirtualRepository = $ambienteVirtualRepository;
        $this->tutorGrupoRepository = $tutorGrupoRepository;
    }

    public function handle(TutorVinculadoEvent $event)
    {
        $tutor = $event->getData();
        $grupo = $event->getGroup();

        $pessoa = $this->pessoaRepository->find($tutor->tut_pes_id);

        $name = explode(" ", $pessoa->pes_nome);
        $firstName = array_shift($name);
        $lastName = implode(" ", $name);

        $data['tutor']['ttg_tipo_tutoria'] = $this->tutorGrupoRepository->getTipoTutoria($tutor->tut_id, $grupo->grp_id);
        $data['tutor']['grp_id'] = $grupo->grp_id;
        $data['tutor']['pes_id'] = $tutor->tut_pes_id;
        $data['tutor']['firstname'] = $firstName;
        $data['tutor']['lastname'] = $lastName;
        $data['tutor']['email'] = $pessoa->pes_email;
        $data['tutor']['username'] = $pessoa->pes_email;
        $data['tutor']['password'] = "changeme";
        $data['tutor']['city'] = "São Luís";

        $ambiente = $this->ambientVirtualRepository->getAmbienteByTurma($grupo->grp_trm_id);

        if (!$ambiente) {
            // Encerra a function sem interromper a propagacao do evento
            return true;
        }

        $param['url'] = $ambiente->url;
        $param['token'] = $ambiente->token;
        $param['action'] = 'post';
        $param['functioname'] = 'local_integracao_enrol_tutor';
        $param['data'] = $data;

        $response = Moodle::send($param);
        $status = 3;

        if (array_key_exists('status', $response)) {
            // Migracao bem-sucedida
            if ($response['status'] == 'success') {
                $status = 2;
            }
        }

        event(new AtualizarSyncEvent($tutor, $status, $response['message']));
    }
}