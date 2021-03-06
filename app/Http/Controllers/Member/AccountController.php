<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class AccountController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth', [
            'except' => [
                'verify_email_address',
            ],
        ]);

        $this->middleware('account_check', [
            'except' => [
                'verify_email_address',
            ],
        ]);
    }

    public function request_email_verification(Request $request)
    {
        # Request BODY validation
        $validationRules =  [
            'ip_address' => 'required|max:30|ip',
        ];
        $errors = $this->staticValidation($request->all(), $validationRules);
        if (count($errors) > 0) {
            $respMessage = $errors->first();
            return $this->respondWithMissingField($respMessage);
        };

        # Selected member data from AuthMiddleware
        $member = $request->member;

        DB::beginTransaction();
        try {
            # Check if email already verified
            if ($member->email_address_verify_status == 'VERIFIED') {
                DB::rollback();
                $respMessage = trans('messages.EmailStatusAlreadyVerified');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Set Key EXPIRED with passed "expired_time"
            DB::table('key_user')
                ->where([
                    'type' => 'VERIFYEMAILADDRESS',
                    'status' => 'ACTIVE',
                ])
                ->where('expired_time', '<', date('Y-m-d H:i:s'))
                ->update([
                    'status' => 'EXPIRED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            # Select table Key User
            $key_user = DB::table('key_user')
                ->where([
                    'code' => $member->code,
                    'status' => 'ACTIVE',
                    'type' => 'VERIFYEMAILADDRESS',
                ])
                ->where('expired_time', '>', date('Y-m-d H:i:s'));

            if ($key_user->get()->count() == 1) {
                # Key is exist

                # Object "key_user" with all column selected
                $key_user = $key_user->first();

                $key_verification = $key_user->value;
            } else {
                # Key didn't exist

                $key_verification = Str::random(32);
                $key_verification = Crypt::encrypt($key_verification);

                # Key will be expired in 30 minutes
                $expired_time = date('Y-m-d H:i:s', strtotime((date('Y-m-d H:i:s') . ' +30 minutes')));

                # Insert
                $valueDB = [
                    'code' => $member->code,
                    'ip_address' => $request->input('ip_address'),
                    'status' => 'ACTIVE',
                    'type' => 'VERIFYEMAILADDRESS',
                    'value' => $key_verification,
                    'expired_time' => $expired_time,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                DB::table('key_user')->insert($valueDB);
            }

            # Select table email_outbox
            $email_outbox = DB::table('email_outbox')
                ->where([
                    'recipient' => $member->email_address,
                    'status' => 'INQUIRY',
                ])
                ->orderBy('created_at', 'desc');

            if ($email_outbox->get()->count() > 0) {
                # Email is exist

                # Object "email_outbox" with all column selected
                $email_outbox = $email_outbox->first();

                # Count interval send email request
                $to = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s'));
                $from = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $email_outbox->created_at);

                # Difference in minutes
                $diff_in_minutes = $to->diffInMinutes($from);

                # Interval request send email must after 5 minutes
                if ($diff_in_minutes <= 5) {
                    DB::rollback();
                    $respMessage = trans('messages.RequestEmailAlreadySavedPleaseWait');
                    return $this->respondFailedWithMessage($respMessage);
                }
            }

            # Minified html of email body
            $body = "<!DOCTYPE html><html lang='en'><head><style>@media only screen and (max-width: 700px){#container{margin:0px !important}}</style></head><body style=' background-color: #fff; margin: 40px; font: 13px/20px normal Helvetica, Arial, sans-serif; color: #4F5155; '><div id='container' style='max-width: 100%; margin: 0 100px; border: 1px solid #D0D0D0; box-shadow: 0 0 8px #D0D0D0;'><h1 style='color: #444; background-color: transparent; font-size: 30px; font-weight: 600; margin: 0 0 14px 0; padding: 14px 15px 10px 15px;'> BRN: Verify your email address</h1><div style='width: 50%; height: 15px; background: #007bff; margin: 0 15px;'></div><div style='margin: 0 15px 0 15px;'><p>Please click this button below to verify your email address</p> <a style='display: inline-block; font-weight: 400; text-align: center; white-space: nowrap; vertical-align: middle; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; border: 1px solid transparent; padding: .375rem .75rem; font-size: 1rem; line-height: 1.5; border-radius: .25rem; cursor: pointer; color: #fff; background-color: #007bff; border-color: #007bff; text-decoration: none;' target='_blank' href='[VERIFICATION_LINK]'>Verify Email Address</a><p>If the button didn't work, you can follow this link:</p> <a style='color: #003399; background-color: transparent; font-weight: normal; overflow-wrap: break-word;' target='_blank' href='[VERIFICATION_LINK]'>[VERIFICATION_LINK]</a><p>Regards,</p><p>BRN Teams</p></div><p style='text-align: right; font-size: 11px; border-top: 1px solid #D0D0D0; line-height: 32px; padding: 0 10px 0 10px; margin: 20px 0 0 0;'> Copyright &copy; 2018 BRN.com</p></div></body></html>";

            # Insert verification link to body
            $verification_link = "localhost:2020?key=$key_verification&email=$member->email_address";
            $body = str_replace('[VERIFICATION_LINK]', $verification_link, $body);

            # Insert to email_outbox
            $valueDB = [
                'sender' => 'no-reply@brn.com',
                'recipient' => $member->email_address,
                'subject' => 'BRN - VERIFY YOUR EMAIL ADDRESS',
                'body' => $body,
                'status' => 'INQUIRY',
                'created_at' => date('Y-m-d H:i:s'),
            ];
            DB::table('email_outbox')->insert($valueDB);

            DB::commit();
            $respMessage = trans('messages.RequestEmailAlreadySavedPleaseCheck');
            return $this->respondSuccessWithMessageAndData($respMessage);
        } catch (\Exception $e) {
            $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

            DB::rollback();
            $respMessage = trans('messages.ChangeCannotBeDone');
            return $this->respondFailedWithMessage($respMessage);
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }

    public function change_email_address(Request $request)
    {
        # Request BODY validation
        $validationRules =  [
            'email_address' => 'required|email|max:100',
        ];
        $errors = $this->staticValidation($request->all(), $validationRules);
        if (count($errors) > 0) {
            $respMessage = $errors->first();
            return $this->respondWithMissingField($respMessage);
        };

        # Selected member data from AuthMiddleware
        $member = $request->member;

        DB::beginTransaction();
        try {
            $email_address = strtolower($request->input('email_address'));

            # Check email_address if used by other account
            $check_member = DB::table('member')
                ->where('email_address', $email_address)
                ->where('id', '!=', $member->id);
            if ($check_member->get()->count() > 0) {
                DB::rollback();
                $respMessage = trans('messages.EmailAddressAlreadyRegistered');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Update email_address and set email_address_verify_status to "NOT VERIFIED"
            $affected = DB::table('member')
                ->where('id', $member->id)
                ->update([
                    'account_status' => 'ACTIVE',
                    'email_address' => $email_address,
                    'email_address_verify_status' => 'NOT VERIFIED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected != 1) {
                DB::rollback();
                $respMessage = trans('messages.UpdateDataFailed');
                return $this->respondFailedWithMessage($respMessage);
            } else {
                DB::commit();
                $respMessage = trans('messages.ProccessSuccess');
                return $this->respondSuccessWithMessageAndData($respMessage);
            }
        } catch (\Exception $e) {
            $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

            DB::rollback();
            $respMessage = trans('messages.ChangeCannotBeDone');
            return $this->respondFailedWithMessage($respMessage);
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }

    public function verify_email_address(Request $request)
    {
        # Request BODY validation
        $validationRules =  [
            'email_address' => 'required|email|max:100',
            'key_token' => 'required',
        ];
        $errors = $this->staticValidation($request->all(), $validationRules);
        if (count($errors) > 0) {
            $respMessage = $errors->first();
            return $this->respondWithMissingField($respMessage);
        };

        # Variable initialization
        $email_address = strtolower($request->input('email_address'));

        DB::beginTransaction();
        try {
            # Set Key EXPIRED with passed "expired_time"
            DB::table('key_user')
                ->where([
                    'type' => 'VERIFYEMAILADDRESS',
                    'status' => 'ACTIVE',
                ])
                ->where('expired_time', '<', date('Y-m-d H:i:s'))
                ->update([
                    'status' => 'EXPIRED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);


            # Select table key_user
            $key_user = DB::table('key_user')
                ->where([
                    'type' => 'VERIFYEMAILADDRESS',
                    'value' => $request->input('key_token'),
                ]);

            if ($key_user->get()->count() == 0) {
                DB::rollback();
                $respMessage = trans('messages.KeyTokenNotFound');
                return $this->respondFailedWithMessage($respMessage);
            } else if ($key_user->get()->count() > 1) {
                DB::rollback();
                $respMessage = trans('messages.KeyTokenRegisteredMoreThanOnce');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Object "key_user" with all column selected
            $key_user = $key_user->first();

            # Check status value key_user
            if ($key_user->status == 'USED') {
                DB::rollback();
                $respMessage = trans('messages.KeyTokenAlreadyUsed');
                return $this->respondFailedWithMessage($respMessage);
            } else if ($key_user->status == 'EXPIRED') {
                DB::rollback();
                $respMessage = trans('messages.KeyTokenExpired');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Select table member
            $member = DB::table('member')
                ->where([
                    'email_address' => $email_address,
                ]);

            if ($member->get()->count() == 0) {
                DB::rollback();
                $respMessage = trans('messages.MemberAccountNotFound');
                return $this->respondFailedWithMessage($respMessage);
            } else if ($member->get()->count() > 1) {
                DB::rollback();
                $respMessage = trans('messages.MemberRegisteredMoreThanOnce');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Object "member" with all column selected
            $member = $member->first();

            # Check if email_address match key_token
            if ($member->code != $key_user->code) {
                DB::rollback();
                $respMessage = trans('messages.KeyTokenAndEmailAddressDidNotMatch');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Check if email_address already verified
            if ($member->email_address_verify_status == 'VERIFIED') {
                DB::rollback();
                $respMessage = trans('messages.EmailStatusAlreadyVerified');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Update member: set email_address_verify_status to "VERIFIED"
            $affected = DB::table('member')
                ->where('id', $member->id)
                ->update([
                    'email_address_verify_status' => 'VERIFIED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected != 1) {
                DB::rollback();
                $respMessage = trans('messages.UpdateDataFailed');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Update key_user: set status to "USED"
            $affected = DB::table('key_user')
                ->where('id', $key_user->id)
                ->update([
                    'status' => 'USED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected != 1) {
                DB::rollback();
                $respMessage = trans('messages.UpdateDataFailed');
                return $this->respondFailedWithMessage($respMessage);
            }

            DB::commit();
            $respMessage = trans('messages.ProccessSuccess');
            return $this->respondSuccessWithMessageAndData($respMessage);
        } catch (\Exception $e) {
            $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

            DB::rollback();
            $respMessage = trans('messages.ChangeCannotBeDone');
            return $this->respondFailedWithMessage($respMessage);
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }

    public function request_phone_verification(Request $request)
    {
        # Request BODY validation
        $validationRules =  [
            'ip_address' => 'required|max:30|ip',
        ];
        $errors = $this->staticValidation($request->all(), $validationRules);
        if (count($errors) > 0) {
            $respMessage = $errors->first();
            return $this->respondWithMissingField($respMessage);
        };

        # Selected member data from AuthMiddleware
        $member = $request->member;

        try {
            # Check if phone already verified
            if ($member->phone_number_verify_status == 'VERIFIED') {
                DB::rollback();
                $respMessage = trans('messages.PhoneStatusAlreadyVerified');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Set Key EXPIRED with passed "expired_time"
            DB::table('key_user')
                ->where([
                    'type' => 'VERIFYPHONENUMBER',
                    'status' => 'ACTIVE',
                ])
                ->where('expired_time', '<', date('Y-m-d H:i:s'))
                ->update([
                    'status' => 'EXPIRED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            # Select table Key User
            $key_user = DB::table('key_user')
                ->where([
                    'code' => $member->code,
                    'status' => 'ACTIVE',
                    'type' => 'VERIFYPHONENUMBER',
                ])
                ->where('expired_time', '>', date('Y-m-d H:i:s'));

            if ($key_user->get()->count() == 1) {
                # Key is exist

                # Object "key_user" with all column selected
                $key_user = $key_user->first();

                $key_verification = $key_user->value;
            } else {
                # Key didn't exist

                $key_verification = mt_rand(100000, 999999);
                $key_verification = Crypt::encrypt($key_verification);

                # Key will be expired in 30 minutes
                $expired_time = date('Y-m-d H:i:s', strtotime((date('Y-m-d H:i:s') . ' +30 minutes')));

                # Insert
                $valueDB = [
                    'code' => $member->code,
                    'ip_address' => $request->input('ip_address'),
                    'status' => 'ACTIVE',
                    'type' => 'VERIFYPHONENUMBER',
                    'value' => $key_verification,
                    'expired_time' => $expired_time,
                    'created_at' => date('Y-m-d H:i:s'),
                ];
                DB::table('key_user')->insert($valueDB);
            }

            # Select table sms_outbox
            $sms_outbox = DB::table('sms_outbox')
                ->where([
                    'recipient' => $member->phone_number,
                    'status' => 'INQUIRY',
                ])
                ->orderBy('created_at', 'desc');

            if ($sms_outbox->get()->count() > 0) {
                # SMS is exist

                # Object "sms_outbox" with all column selected
                $sms_outbox = $sms_outbox->first();

                # Count interval send SMS request
                $to = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s'));
                $from = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $sms_outbox->created_at);

                # Difference in minutes
                $diff_in_minutes = $to->diffInMinutes($from);

                # Interval request send SMS must after 5 minutes
                if ($diff_in_minutes <= 5) {
                    DB::rollback();
                    $respMessage = trans('messages.RequestSMSAlreadySavedPleaseWait');
                    return $this->respondFailedWithMessage($respMessage);
                }
            }

            # Template SMS Message
            $message = "BRN - Kode verifikasi Anda adalah: [VERIFICATION_NUMBER]. Jangan memberitahu siapapun kode ini termasuk pihak Kami.";
            $key_verification = Crypt::decrypt($key_verification);

            # Insert verification code to message
            $message = str_replace('[VERIFICATION_NUMBER]', $key_verification, $message);

            # Insert to sms_outbox
            $valueDB = [
                'recipient' => $member->phone_number,
                'message' => $message,
                'status' => 'INQUIRY',
                'created_at' => date('Y-m-d H:i:s'),
            ];
            DB::table('sms_outbox')->insert($valueDB);

            DB::commit();
            $respMessage = trans('messages.RequestSMSAlreadySavedPleaseCheck');
            return $this->respondSuccessWithMessageAndData($respMessage);
        } catch (\Exception $e) {
            $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

            DB::rollback();
            $respMessage = trans('messages.ChangeCannotBeDone');
            return $this->respondFailedWithMessage($respMessage);
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }

    public function verify_phone_number(Request $request)
    {
        # Request BODY validation
        $validationRules =  [
            'key_token' => 'required',
        ];
        $errors = $this->staticValidation($request->all(), $validationRules);
        if (count($errors) > 0) {
            $respMessage = $errors->first();
            return $this->respondWithMissingField($respMessage);
        };

        $key_token = $request->input('key_token');

        # Selected member data from AuthMiddleware
        $member = $request->member;

        DB::beginTransaction();
        try {
            # Set Key EXPIRED with passed "expired_time"
            DB::table('key_user')
                ->where([
                    'type' => 'VERIFYPHONENUMBER',
                    'status' => 'ACTIVE',
                ])
                ->where('expired_time', '<', date('Y-m-d H:i:s'))
                ->update([
                    'status' => 'EXPIRED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            # Select table key_user
            $key_user = DB::table('key_user')
                ->where([
                    'type' => 'VERIFYPHONENUMBER',
                    'code' => $member->code,
                ])
                ->orderBy('id', 'desc');

            # Object "key_user" with all column selected
            $key_user = $key_user->first();

            # Check status value key_user
            if ($key_user->status == 'USED') {
                DB::commit();
                $respMessage = trans('messages.KeyTokenAlreadyUsed');
                return $this->respondFailedWithMessage($respMessage);
            } else if ($key_user->status == 'EXPIRED') {
                DB::commit();
                $respMessage = trans('messages.KeyTokenExpired');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Check if key_token match user request
            if ($key_token != Crypt::decrypt($key_user->value)) {
                DB::commit();
                $respMessage = trans('messages.KeyTokenAndPhoneNumberDidNotMatch');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Check if phone_number_verify_status already verified
            if ($member->phone_number_verify_status == 'VERIFIED') {
                DB::commit();
                $respMessage = trans('messages.PhoneStatusAlreadyVerified');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Update member: set phone_number_verify_status to "VERIFIED"
            $affected = DB::table('member')
                ->where('id', $member->id)
                ->update([
                    'phone_number_verify_status' => 'VERIFIED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected != 1) {
                DB::rollback();
                $respMessage = trans('messages.UpdateDataFailed');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Update key_user: set status to "USED"
            $affected = DB::table('key_user')
                ->where('id', $key_user->id)
                ->update([
                    'status' => 'USED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected != 1) {
                DB::rollback();
                $respMessage = trans('messages.UpdateDataFailed');
                return $this->respondFailedWithMessage($respMessage);
            }

            DB::commit();
            $respMessage = trans('messages.ProccessSuccess');
            return $this->respondSuccessWithMessageAndData($respMessage);
        } catch (\Exception $e) {
            $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

            DB::rollback();
            $respMessage = trans('messages.ChangeCannotBeDone');
            return $this->respondFailedWithMessage($respMessage);
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }

    public function change_phone_number(Request $request)
    {
        # Request BODY validation
        $validationRules =  [
            # (62)  : first 2 digit must be "62"
            # \d+$  : remaining character must be number
            # 27 char max, because remaining 3 digit for "(","+",")" and total will be 30 digit. example: (+62).....
            'phone_number' => 'required|max:27|regex:/(62)\d+$/',
        ];
        $errors = $this->staticValidation($request->all(), $validationRules);
        if (count($errors) > 0) {
            $respMessage = $errors->first();
            return $this->respondWithMissingField($respMessage);
        };

        $phone_number = $request->input('phone_number');
        # Check if first 2 digit is "62"
        if (substr($phone_number, 0, 2) == 62) {
            # Replace "62" with (+62)
            $phone_number = substr($phone_number, 2);
            $phone_number = "(+62)$phone_number";
        } else {
            $respMessage = trans('messages.ValueMustBeValidPhoneNumber');
            return $this->respondFailedWithMessage($respMessage);
        }

        # Selected member data from AuthMiddleware
        $member = $request->member;

        DB::beginTransaction();
        try {
            # Check phone_number if used by other account
            $check_member = DB::table('member')
                ->where('phone_number', $phone_number)
                ->where('id', '!=', $member->id);
            if ($check_member->get()->count() > 0) {
                DB::rollback();
                $respMessage = trans('messages.PhoneNumberAlreadyRegistered');
                return $this->respondFailedWithMessage($respMessage);
            }

            # Update phone_number and set phone_number_verify_status to "NOT VERIFIED"
            $affected = DB::table('member')
                ->where('id', $member->id)
                ->update([
                    'account_status' => 'ACTIVE',
                    'phone_number' => $phone_number,
                    'phone_number_verify_status' => 'NOT VERIFIED',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected != 1) {
                DB::rollback();
                $respMessage = trans('messages.UpdateDataFailed');
                return $this->respondFailedWithMessage($respMessage);
            }

            DB::commit();
            $respMessage = trans('messages.ProccessSuccess');
            return $this->respondSuccessWithMessageAndData($respMessage);
        } catch (\Exception $e) {
            $this->sendApiErrorToTelegram($request->fullUrl(), $request->header(), $request->all(), $e->getMessage());

            DB::rollback();
            $respMessage = trans('messages.ChangeCannotBeDone');
            return $this->respondFailedWithMessage($respMessage);
        }

        $respMessage = trans('messages.Error');
        return $this->respondFailedWithMessage($respMessage);
    }
}
