<?php

namespace App\Integrations\ServerManagers\DockerMailServer\Charts;

use App\Integrations\ServerManagers\Kubernetes\Charts\Job\JobChart;
use App\Integrations\ServerManagers\Kubernetes\Charts\Job\RebuildableJobChart;
use App\OrgDomain;
use Illuminate\Support\Str;

class MailserverJobChart extends JobChart implements RebuildableJobChart
{
    public $chart = [];

    public function __construct(public OrgDomain $domain) {}

    public function taskValues(): array
    {
        return ['org_domain_id' => $this->domain->id];
    }

    public static function fromTaskValues(array $values): static
    {
        return new static(OrgDomain::findOrFail($values['org_domain_id'] ?? null));
    }

    public function run(array $command, array $env, array $args, ?string $job_name = null)
    {
        if (! $job_name) {
            $job_name = 'mailserver-job-'.Str::lower(Str::random(10));
        }

        $this->chart = [
            'apiVersion' => 'batch/v1',
            'kind' => 'Job',
            'metadata' => [
                'annotations' => [
                    'test' => 'annonation',
                ],
                'labels' => [
                    'test' => 'label',
                ],
                'name' => $job_name,
                'namespace' => $this->domain->email_server->server->setting('namespace'),
            ],
            'spec' => [
                'backoffLimit' => 6,
                'completionMode' => 'NonIndexed',
                'completions' => 1,
                'parallelism' => 1,
                'ttlSecondsAfterFinished' => 600,
                'suspend' => false,
                'template' => [
                    'spec' => [
                        'containers' => [[
                            'env' => $env,
                            'command' => $command,
                            'args' => $args,
                            'image' => 'ghcr.io/docker-mailserver/docker-mailserver:latest',
                            'imagePullPolicy' => 'Always',
                            'name' => 'mailserver-job',
                            'terminationMessagePath' => '/dev/termination-log',
                            'terminationMessagePolicy' => 'File',
                            'volumeMounts' => [
                                [
                                    'mountPath' => '/var/mail/',
                                    'name' => 'mail-data',
                                ],
                                [
                                    'mountPath' => '/tmp/docker-mailserver',
                                    'name' => 'mail-config',
                                ],
                                [
                                    'mountPath' => '/var/mail-state',
                                    'name' => 'mail-state',
                                ],
                            ],
                        ]],
                        'dnsPolicy' => 'ClusterFirst',
                        'restartPolicy' => 'Never',
                        'schedulerName' => 'default-scheduler',
                        'terminationGracePeriodSeconds' => 30,
                        'volumes' => [[
                            'name' => 'mail-data',
                            'persistentVolumeClaim' => [
                                'claimName' => 'mailserver-docker-mailserver-mail-data',
                            ],
                        ], [
                            'name' => 'mail-config',
                            'persistentVolumeClaim' => [
                                'claimName' => 'mailserver-docker-mailserver-mail-config',
                            ],
                        ], [
                            'name' => 'mail-state',
                            'persistentVolumeClaim' => [
                                'claimName' => 'mailserver-docker-mailserver-mail-state',
                            ],
                        ]],
                    ],
                ],
            ],
        ];
    }
}
