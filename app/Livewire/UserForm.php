<?php

namespace App\Livewire;

use Livewire\Component;
use GuzzleHttp\Client;
use App\Models\Basvuru;

use FacebookAds\Api;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;
use Illuminate\Support\Facades\Log;

class UserForm extends Component
{
    public $ad;
    public $telefon;
    public $dogumTarihi;
    public $musteriMi;
    public $tcKimlik;

    protected $rules = [
        "ad" => "required|min:3|max:40",
        "telefon" => 'required|regex:/^5[0-9]{9}$/',
        "dogumTarihi" => "required|date|before:1990-01-01",
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
        "dogumTarihi.before" => "Doğum Tarihi 1990'dan büyük olamaz.",
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
                $this->sendMetaLeadEvent();
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

    private function sendMetaLeadEvent(): void
    {
        // Meta configuration
        $access_token = env("META_ACCESS_TOKEN");
        $pixel_id = env("META_PIXEL_ID");

        // Check if access token and pixel ID are set
        if (!$access_token || !$pixel_id) {
            Log::error("Meta access token or pixel ID is missing.");
            return;
        }

        // Initialize Meta API
        Api::init(null, null, $access_token);
        $api = Api::instance();
        $api->setLogger(new CurlLogger());

        // Event data
        $event_time = time();
        $event_id = uniqid("", true);
        $client_user_agent = request()->userAgent();
        $client_ip_address = request()->ip();
        $formatted_birthdate = date("Ymd", strtotime($this->dogumTarihi));
        $event_source_url = url()->current();

        // Get cookies
        $fbc = request()->cookie("_fbc") ?? "";
        $fbp = request()->cookie("_fbp") ?? "";

        // Build user data
        $user_data = (new UserData())
            ->setPhones([$this->telefon])
            ->setClientUserAgent($client_user_agent)
            ->setClientIpAddress($client_ip_address)
            ->setDateOfBirth($formatted_birthdate)
            ->setFbc($fbc)
            ->setFbp($fbp);

        // Build event data
        $event = (new Event())
            ->setEventName("Lead")
            ->setEventTime($event_time)
            ->setUserData($user_data)
            ->setActionSource("website")
            ->setEventSourceUrl($event_source_url)
            ->setEventId($event_id);

        // Send event request
        try {
            $request = (new EventRequest($pixel_id))->setEvents([$event]);
            $response = $request->execute();
            Log::info("Meta event sent successfully", [
                "response" => $response,
            ]);
        } catch (\Exception $e) {
            Log::error("Error sending Meta event", [
                "error" => $e->getMessage(),
            ]);
        }
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
