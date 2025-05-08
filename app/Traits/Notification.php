<?php

namespace App\Traits;

use App\Helpers\ResponseError;
use App\Models\Order;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Models\User;
use App\Services\PushNotificationService\PushNotificationService;
use Cache;
use Google\Client;
use Illuminate\Support\Facades\Http;
use Log;
use Throwable;

/**
 * App\Traits\Notification
 *
 * @property string $language
 */
trait Notification
{
	public function sendNotification(
		array   $receivers = [],
		?string $message = '',
		?string $title = null,
		mixed   $data = [],
		array   $userIds = [],
		?string $firebaseTitle = '',
	): void
	{
//		dispatch(function () use ($receivers, $message, $title, $data, $userIds, $firebaseTitle) {
//
		if (empty($receivers)) {
			return;
		}

		$type = data_get($data, 'order.type');

		Log::error(is_array($userIds) && count($userIds) > 0, [
			'type' => $type ?? data_get($data, 'type'),
			'title' => $title,
			'body' 	=> $message,
			'data' 	=> $data,
			'sound' => 'default',
		]);

		if (is_array($userIds) && count($userIds) > 0) {

			(new PushNotificationService)->storeMany([
				'type' => $type ?? data_get($data, 'type'),
				'title' => $title,
					'body' 	=> $message,
					'data' 	=> $data,
					'sound' => 'default',
				], $userIds);
			}

		$url = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";

		$token = $this->updateToken();

		$headers = [
			'Authorization' => "Bearer $token",
			'Content-Type' => 'application/json'
		];

		foreach ($receivers as $receiver) {

			try {
				dispatch(function () use ($receiver, $message, $title, $data, $firebaseTitle, $headers, $url) {

					if (empty($receiver)) {
						return;
					}

					$request = Http::withHeaders($headers)->post($url, [ // $request =
						'message' => [
							'token' => $receiver,
							'notification' => [
								'title' => $firebaseTitle ?? $title,
								'body' => $message,
							],
							'data' => [
								'id' => (string)($data['id'] ?? ''),
								'status' => (string)($data['status'] ?? ''),
								'type' => (string)($data['type'] ?? '')
							],
							'android' => [
								'notification' => [
									'sound' => 'default',
								]
							],
							'apns' => [
								'payload' => [
									'aps' => [
										'sound' => 'default'
									]
								]
							]
						]
					]);

					Log::error($request->status(), [$receiver]);

				})->afterResponse();

			} catch (Throwable $e) {
				Log::error('catch ' . $e->getMessage());
			}
		}

//		})->afterResponse();
	}

	public function sendAllNotification(?string $title = null, mixed $data = [], ?string $firebaseTitle = ''): void
	{
		dispatch(function () use ($title, $data, $firebaseTitle) {

			User::select([
				'id',
				'deleted_at',
				'active',
				'email_verified_at',
				'phone_verified_at',
				'firebase_token',
			])
				->where('active', 1)
				->where(fn($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))
				->whereNotNull('firebase_token')
				->orderBy('id')
				->chunk(100, function ($users) use ($title, $data, $firebaseTitle) {

					$firebaseTokens = $users?->pluck('firebase_token', 'id')?->toArray();

					$receives = [];

					foreach ($firebaseTokens as $firebaseToken) {

						if (empty($firebaseToken)) {
							continue;
						}

						$receives[] = array_filter($firebaseToken, fn($item) => !empty($item));
					}

					$receives = array_merge(...$receives);

					$this->sendNotification(
						$receives,
						$title,
						data_get($data, 'id'),
						$data,
						array_keys(is_array($firebaseTokens) ? $firebaseTokens : []),
						$firebaseTitle
					);

				});

		})->afterResponse();

	}

	private function updateToken(): string
	{
		$googleClient = new Client;
		$googleClient->setAuthConfig(storage_path('app/google-service-account.json'));
		$googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');

		$token = $googleClient->fetchAccessTokenWithAssertion()['access_token'];

		return Cache::remember('firebase_auth_token', 300, fn() => $token);
	}

	public function newOrderNotification(Order $order): void
	{
		$adminFirebaseTokens = User::with(['roles' => fn($q) => $q->where('name', 'admin')])
			->whereHas('roles', fn($q) => $q->where('name', 'admin'))
			->whereNotNull('firebase_token')
			->pluck('firebase_token', 'id')
			->toArray();

		$sellersFirebaseTokens = User::with([
			'shop' => fn($q) => $q->where('id', $order->shop_id)
		])
			->whereHas('shop', fn($q) => $q->where('id', $order->shop_id))
			->whereNotNull('firebase_token')
			->pluck('firebase_token', 'id')
			->toArray();

		$aTokens = [];
		$sTokens = [];

		foreach ($adminFirebaseTokens as $adminToken) {
			$aTokens = array_merge($aTokens, is_array($adminToken) ? array_values($adminToken) : [$adminToken]);
		}

		foreach ($sellersFirebaseTokens as $sellerToken) {
			$sTokens = array_merge($sTokens, is_array($sellerToken) ? array_values($sellerToken) : [$sellerToken]);
		}

		$this->sendNotification(
			array_values(array_unique(array_merge($aTokens, $sTokens))),
			__('errors.' . ResponseError::NEW_ORDER, ['id' => $order->id], $this->language),
            $order->id,
            $order->setAttribute('type', PushNotification::NEW_ORDER)?->only(['id', 'status', 'delivery_type']),
			array_merge(array_keys($adminFirebaseTokens), array_keys($sellersFirebaseTokens))
		);

	}

	private function projectId()
	{
		return Settings::where('key', 'project_id')->value('value');
	}
}
