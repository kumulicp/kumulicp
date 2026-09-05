<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type 'image', 'helm', or 'both'
 * @property string $name
 * @property string $registry
 * @property string|null $username
 * @property string|null $password
 */
class RepoSecret extends Model
{
    use HasFactory;

    const TYPE_IMAGE = 'image';

    const TYPE_HELM = 'helm';

    const TYPE_BOTH = 'both';

    protected $fillable = [
        'type',
        'name',
        'registry',
        'username',
        'password',
    ];

    protected $casts = [
        'password' => 'encrypted',
    ];

    protected $hidden = [
        'password',
    ];

    public function versions()
    {
        return AppVersion::where(function ($query) {
            if ($this->type !== self::TYPE_HELM) {
                $query->orWhere('pull_secret_id', $this->id);
            }
            if ($this->type !== self::TYPE_IMAGE) {
                $query->orWhere('helm_repo_secret_id', $this->id);
            }
        });
    }

    public function requiresAuth(): bool
    {
        return ! empty($this->username);
    }

    public function k8sSecretName(): string
    {
        return 'pull-secret-'.$this->id;
    }

    public function dockerConfigJson(): string
    {
        return json_encode([
            'auths' => [
                $this->registry => [
                    'username' => (string) $this->username,
                    'password' => (string) $this->password,
                    'auth' => base64_encode($this->username.':'.$this->password),
                ],
            ],
        ]);
    }

    public function inUse(): bool
    {
        return AppInstance::whereHas('version', function ($query) {
            $query->where('pull_secret_id', $this->id)->orWhere('helm_repo_secret_id', $this->id);
        })->exists();
    }
}
