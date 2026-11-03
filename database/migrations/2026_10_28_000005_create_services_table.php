<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('description', 255);
            $table->string('icon', 40)->default('briefcase');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        $now = now();
        $seed = [
            ['lead_generation', 'B2B Lead Generation', 'Targeted prospect lists built on your ICP.', 'target', 1],
            ['prospect_research', 'Prospect Research', 'Manual research on companies and decision-makers.', 'search', 2],
            ['icp_qualification', 'ICP Qualification', 'Prospects reviewed against your requirements.', 'circle_check', 3],
            ['outreach_databases', 'Outreach-Ready Data', 'Verified, segmented, CRM-ready databases.', 'database', 4],
            ['cold_outreach', 'Cold Outreach', 'Email, calling, and appointment setting.', 'megaphone', 5],
            ['virtual_assistance', 'Virtual Assistance', 'Admin, CRM, research, and operations support.', 'laptop', 6],
            ['it_service_desk', 'IT Service Desk', 'Remote technical and user support.', 'headphones', 7],
            ['flexible_support', 'Flexible Support', 'A custom workflow around your business.', 'users', 8],
        ];

        DB::table('services')->insert(array_map(
            fn (array $row) => [
                'slug' => $row[0],
                'name' => $row[1],
                'description' => $row[2],
                'icon' => $row[3],
                'is_active' => true,
                'sort_order' => $row[4],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $seed,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
