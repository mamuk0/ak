<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Basvuru extends Model
{
    use HasFactory;

    protected $table = "basvurular";

    protected $fillable = [
        "ad",
        "telefon",
        "dogum_tarihi",
        "musteri_mi",
        "tc_kimlik",
    ];
}
