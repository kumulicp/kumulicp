<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $registry
 * @property string|null $username
 * @property string|null $password
 */
class PullSecret extends Model
{
    use HasFactory;

    protected $fillable = [
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
        return $this->hasMany(AppVersion::class, 'pull_secret_id');
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
            $query->where('pull_secret_id', $this->id);
        })->exists();
    }
}
