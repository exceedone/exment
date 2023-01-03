<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Login\LoginService;

class ResetPasswordCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:resetpassword {--id=} {--email=} {--user_code=} {--password=} {--random=0} {--send=0} {--reset_first_login=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset password for a specific admin user';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    protected function getParameters()
    {
        $options = [];

        // get parameters
        $options['id'] = $this->option("id");
        $options['email'] = $this->option("email");
        $options['user_code'] = $this->option("user_code");
        $options['password'] = $this->option("password");
        $options['random'] = $this->option("random");
        $options['send'] = $this->option("send");
        $options['reset_first_login'] = $this->option("reset_first_login");

        if (!$options['id'] && !$options['email'] && !$options['user_code']) {
            throw new \Exception('please set one of parameters, id, email or user_code.');
        }
        if (!isset($options['password']) && !boolval($options['random'])) {
            throw new \Exception('please set password or random parameter.');
        }
        if ($options['random']) {
            if (!preg_match("/^[0,1]$/", $options['random'])) {
                throw new \Exception('please specify 1 or 0 for the random parameter');
            }
        }
        if ($options['send']) {
            if (!preg_match("/^[0,1]$/", $options['send'])) {
                throw new \Exception('please specify 1 or 0 for the send parameter');
            }
        }
        if ($options['reset_first_login']) {
            if (!preg_match("/^[0,1]$/", $options['reset_first_login'])) {
                throw new \Exception('please specify 1 or 0 for the reset_first_login parameter');
            }
        }



        if ($options['id']) {
            $user = getModelName(SystemTableName::USER)::find($options['id']);
        } elseif ($options['email']) {
            $user = getModelName(SystemTableName::USER)::where('value->email', $options['email'])->first();
        } elseif ($options['user_code']) {
            $user = getModelName(SystemTableName::USER)::where('value->user_code', $options['user_code'])->first();
        }

        if (!isset($user)) {
            throw new \Exception('optional parameters for target user is invalid.');
        }

        $login_user = $user->login_user;

        // If not has login user, define.
        if (!isset($login_user)) {
            $login_user = new LoginUser([
                'base_user_id' => $user->id,
                'login_type' => Enums\LoginType::PURE,
                'password_reset_flg' => 0,
            ]);
        }

        if ($options['password']) {
            $data = \array_merge($options, ['password_confirmation' => $options['password']]);
            $rules = [
                'password' => get_password_rule(true, $login_user),
                ];
            $validation = \Validator::make($data, $rules);
            if ($validation->fails()) {
                $messages = collect($validation->errors()->messages());
                $message = $messages->map(function ($message) {
                    return $message[0];
                });
                throw new \Exception('password error :' . implode("\r\n", $message->values()->toArray()));
            }
        } else {
            $options['password'] = make_password();
        }

        return [$login_user, $options];
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            // get parameters
            list($login_user, $options) = $this->getParameters();

            LoginService::resetPassword($login_user, [
                'password' => $options['password'],
                'send_password' => $options['send'],
                'password_reset_flg' => $options['reset_first_login'],
            ]);

            if (boolval($options['random']) && !boolval($options['send'])) {
                $this->line(exmtrans('command.resetpassword.notify_password', $options['password']));
            } else {
                $this->line(exmtrans('command.resetpassword.success'));
            }
        } catch (\Exception $e) {
            \Log::error($e);
            $this->error($e->getMessage());
            return -1;
        }

        return 0;
    }
}
