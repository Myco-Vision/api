<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Species;

class SpeciesSeeder extends Seeder
{
    public function run(): void
    {
        $species = [
            [
                'name' => 'Mamuno',
                'scientific_name' => 'Termitomyces',
                'classification' => 'edible',
                'description' => 'A genus of edible mushrooms that grow out of termite mounds. They are highly prized for their flavor and texture in the Philippines.',
                'habitat' => 'Termite mounds in tropical forests and grasslands',
            ],
            [
                'name' => 'Talingang Daga',
                'scientific_name' => 'Auricularia',
                'classification' => 'edible',
                'description' => 'Also known as Wood Ear, these are gelatinous mushrooms often used in soups and stir-fries. They grow on decaying wood.',
                'habitat' => 'Decaying logs and branches in moist environments',
            ],
            [
                'name' => 'Destroying Angel',
                'scientific_name' => 'Amanita virosa',
                'classification' => 'poisonous',
                'description' => 'A deadly poisonous mushroom. It is purely white and contains amatoxins which cause liver and kidney failure.',
                'habitat' => 'Mixed woodlands and sometimes near grassy areas',
            ],
            [
                'name' => 'Flowerpot Parasol',
                'scientific_name' => 'Leucocoprinus birnbaumii',
                'classification' => 'poisonous',
                'description' => 'A small yellow mushroom often found in flowerpots and greenhouses. It is toxic if ingested.',
                'habitat' => 'Flowerpots, greenhouses, and nutrient-rich soil',
            ],
        ];

        foreach ($species as $item) {
            Species::updateOrCreate(
                ['scientific_name' => $item['scientific_name']],
                $item
            );
        }
    }
}
