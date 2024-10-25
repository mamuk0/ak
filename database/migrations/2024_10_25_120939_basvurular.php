<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create("basvurular", function (Blueprint $table) {
            $table->id();
            $table->string("ad");
            $table->string("telefon")->unique();
            $table->date("dogum_tarihi");
            $table->boolean("musteri_mi");
            $table->string("tc_kimlik")->unique();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists("basvurular");
    }
};
