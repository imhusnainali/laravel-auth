<?php namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesAndRegistersUsers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades;
use App\Logic\User\UserRepository;
use App\User;
use App\Models\Social;
use App\Models\Role;
use App\Traits\CaptchaTrait;
use Laravel\Socialite\Facades\Socialite;
use Validator;

class AuthController extends Controller {

	/*
	|--------------------------------------------------------------------------
	| Registration & Login Controller
	|--------------------------------------------------------------------------
	|
	| This controller handles the registration of new users, as well as the
	| authentication of existing users. By default, this controller uses
	| a simple trait to add these behaviors. Why don't you explore it?
	|
	*/

	use CaptchaTrait;
	use AuthenticatesAndRegistersUsers;
    protected $auth;
    protected $userRepository;

	/**
	 * Create a new authentication controller instance.
	 *
	 * @param  \Illuminate\Contracts\Auth\Guard  $auth
	 * @param  \Illuminate\Contracts\Auth\Registrar  $registrar
	 * @return void
	 */
	public function __construct(Guard $auth, UserRepository $userRepository)
	{

		$this->middleware('guest',
			['except' =>
				['getLogout', 'resendEmail', 'activateAccount']]);

        $this->auth = $auth;
        $this->userRepository = $userRepository;

	}

	/**
	 * Get a validator for an incoming registration request.
	 *
	 * @param  array  $data
	 * @return \Illuminate\Contracts\Validation\Validator
	 */
	public function validator(array $data)
	{
		return Validator::make($data, [
				'name' 			=> 'required|max:255',
				'first_name' 	=> 'required|max:255',
				'last_name' 	=> 'required|max:255',
				'email' 		=> 'required|email|max:255|unique:users',
				'password' 		=> 'required|confirmed|min:6',
			]);
	}

	/**
	 * Handle a registration request for the application.
	 *
	 * @param  \Illuminate\Http\Request  $request
	 * @return \Illuminate\Http\Response
	 */
	public function postRegister(Request $request)
	{
	    if($this->captchaCheck() == false)
	    {
	        return redirect()->back()
	            ->withErrors(['Sorry, Wrong Captcha'])
	            ->withInput();
	    }

		$validator = $this->validator($request->all());

        if ($validator->fails()) {
            $this->throwValidationException(
                $request, $validator
            );
        }

		$activation_code = str_random(60) . $request->input('email');
		$user = new User;
		$user->name = $request->input('name');
		$user->first_name = $request->input('first_name');
		$user->last_name = $request->input('last_name');
		$user->email = $request->input('email');
		$user->password = bcrypt($request->input('password'));
		$user->activation_code = $activation_code;
        $user_role = Role::whereName('user')->first();
        $user->assignRole($user_role);

		if ($user->save()) {

			$this->sendEmail($user);

			return view('auth.activateAccount')
				->with('email', $request->input('email'));

		} else {

			\Session::flash('message', \Lang::get('notCreated') );
			return redirect()->back()->withInput();
		}
	}
	//*/

	public function sendEmail(User $user)
	{
		$data = array(
				'name' => $user->name,
				'code' => $user->activation_code,
		);

		\Mail::queue('emails.activateAccount', $data, function($message) use ($user) {
			$message->subject( \Lang::get('auth.pleaseActivate') );
			$message->to($user->email);
		});
	}

	public function resendEmail()
	{
		$user = \Auth::user();
		if( $user->resent >= 3 )
		{
			return view('auth.tooManyEmails')
				->with('email', $user->email);
		} else {
			$user->resent = $user->resent + 1;
			$user->save();
			$this->sendEmail($user);
			return view('auth.activateAccount')
				->with('email', $user->email);
		}
	}

	public function activateAccount($code, User $user)
	{

		if($user->accountIsActive($code)) {
			\Session::flash('message', \Lang::get('auth.successActivated') );
			return redirect('home');
		}

		\Session::flash('message', \Lang::get('auth.unsuccessful') );
		return redirect('home');

	}

    public function getSocialRedirect( $provider )
    {
        $providerKey = \Config::get('services.' . $provider);
        if(empty($providerKey))
            return view('pages.status')
                ->with('error','No such provider');

        return Socialite::driver( $provider )->redirect();

    }

    public function getSocialHandle( $provider )
    {

        $user = Socialite::driver( $provider )->user();

        $social_user = null;

        //CHECK IF USERS EMAIL ADDRESS IS ALREADY IN DATABASE
        $user_check = User::where('email', '=', $user->email)->first();
        if(!empty($user_check))
        {
            $social_user = $user_check;
        }
        else // USER IS NOT IN DATABASE BASED ON EMAIL ADDRESS
        {

            $same_social_id = Social::where('social_id', '=', $user->id)->where('provider', '=', $provider )->first();
            // CHECK IF NEW SOCIAL MEDIA USER
            if(empty($same_social_id))
            {

                $new_social_user 					= new User;
                $new_social_user->email            	= $user->email;
                $name 								= explode(' ', $user->name);
				if ($user->email) {
					$new_social_user->name         	= $user->email;
				} else {
					$new_social_user->name			= $name[0];
				}
                $new_social_user->first_name      	= $name[0];
                $new_social_user->last_name        	= $name[1];
                $new_social_user->active           	= '1';
				$the_activation_code 				= str_random(60) . $user->email;
				$new_social_user->activation_code 	= $the_activation_code;
                $new_social_user->save();
                $social_data 						= new Social;
                $social_data->social_id 			= $user->id;
                $social_data->provider 				= $provider;
                $new_social_user->social()->save($social_data);

                // Add role
                $role = Role::whereName('user')->first();
                $new_social_user->assignRole($role);

                $social_user = $new_social_user;
            }
            else
            {
                //Load this existing social user
                $social_user = $same_social_id->user;
            }

        }

        $this->auth->login($social_user, true);

        if( $this->auth->user()->hasRole('user'))
        {
            //return redirect()->route('user.home');
        	return redirect('app');
        }

        if( $this->auth->user()->hasRole('administrator'))
        {
        	return redirect('app');
            //return redirect()->route('admin.home');
        }

        return \App::abort(500);
    }
}
