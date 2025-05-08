<?php

namespace App\Services\PaymentService;

use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\User;
use Exception;
use Http;
use Illuminate\Database\Eloquent\Model;
use Str;
use Throwable;

class FlutterWaveService extends BaseService
{
    protected function getModelClass(): string
    {
        return Payout::class;
    }

    /**
     * @param array $data
     * @return PaymentProcess|Model
     * @throws Throwable
     */
    public function orderProcessTransaction(array $data): Model|PaymentProcess
    {
        $payment = Payment::where('tag', Payment::TAG_FLUTTER_WAVE)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

		[$key, $before] = $this->getPayload($data, $payload);
		$modelId 		= data_get($before, 'model_id');

		$totalPrice = round((float)data_get($before, 'total_price') * 100, 2);

		$this->childrenProcess($modelId, data_get($before, 'model_type'));

		$host = request()->getSchemeAndHttpHost();
		$url  = "$host/order-stripe-success?$key=$modelId";

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . data_get($payload, 'flw_sk')
        ];

        $trxRef = "$modelId-" . time();

		/** @var User $user */
		$user = auth('sanctum')->user() ?? (object)[
			'firstname' => 'firstname',
			'lastname'  => 'lastname',
			'phone' 	=> 'phone',
			'email' 	=> Str::random() . '@gmail.com',
		];

        $data = [
            'tx_ref'            => $trxRef,
            'amount'            => $totalPrice,
            'currency'          => Str::upper(data_get($before, 'currency')),
            'payment_options'   => 'card,account,ussd,mobilemoneyghana',
            'redirect_url'      => $url,
            'customer'          => [
                'name'          => "$user->firstname $user?->lastname",
                'email'         => $user->email ?? Str::random() . '@gmail.com'
            ],
            'customizations'    => [
                'title'         => data_get($payload, 'title', ''),
                'description'   => data_get($payload, 'description', ''),
                'logo'          => data_get($payload, 'logo', ''),
            ]
        ];

        $request = Http::withHeaders($headers)->post('https://api.flutterwave.com/v3/payments', $data);

        $body = json_decode($request->body());

        if (data_get($body, 'status') === 'error') {
            throw new Exception(data_get($body, 'message'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $modelId,
			'model_type' => data_get($before, 'model_type')
        ], [
            'id'    => $trxRef,
            'data'  => [
                'url'   	 => data_get($body, 'data.link'),
                'price' 	 => $totalPrice,
				'cart'		 => $data,
				'payment_id' => $payment->id,
            ]
        ]);
    }

    /**
     * @param array $data
     * @param Shop $shop
     * @param $currency
     * @return Model|array|PaymentProcess
     * @throws Exception
     */
    public function subscriptionProcessTransaction(array $data, Shop $shop, $currency): Model|array|PaymentProcess
    {
        $payment = Payment::where('tag', Payment::TAG_FLUTTER_WAVE)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $host           = request()->getSchemeAndHttpHost();

        /** @var Subscription $subscription */
        $subscription   = Subscription::find(data_get($data, 'subscription_id'));

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . data_get($payload, 'flw_sk')
        ];

        $trxRef = "$subscription->id-" . time();

        $data = [
            'tx_ref'            => $trxRef,
            'amount'            => $subscription->price,
            'currency'          => Str::lower(data_get($paymentPayload?->payload, 'currency', $currency)),
            'payment_options'   => 'card,account,ussd,mobilemoneyghana',
            'redirect_url'      => "$host/subscription-stripe-success?subscription_id=$subscription->id",
            'customer'          => [
                'name'          => "{$shop->seller?->firstname} {$shop->seller?->lastname}",
                'phonenumber'   => $shop->seller?->phone,
                'email'         => $shop->seller?->email
            ],
            'customizations'    => [
                'title'         => data_get($payload, 'title', ''),
                'description'   => data_get($payload, 'description', ''),
                'logo'          => data_get($payload, 'logo', ''),
            ]
        ];

        $request = Http::withHeaders($headers)->post('https://api.flutterwave.com/v3/payments', $data);

        $body    = json_decode($request->body());

        if (data_get($body, 'status') === 'error') {
            throw new Exception(data_get($body, 'message'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
			'model_id'   => $subscription->id,
			'model_type' => get_class($subscription)
        ], [
            'id'    => $trxRef,
            'data'  => [
                'url'             => data_get($body, 'data.link'),
                'price'           => round($subscription->price, 2) * 100,
                'shop_id'         => $shop->id,
                'subscription_id' => $subscription->id,
				'payment_id' 	  => $payment->id,
            ]
        ]);
    }
}
