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
            [
                'name' => 'Oyster Mushroom',
                'scientific_name' => 'Pleurotus ostreatus',
                'classification' => 'edible',
                'description' => 'A common edible mushroom with fan- or oyster-shaped caps. Widely cultivated and found in the wild on dead or dying hardwood trees.',
                'habitat' => 'Decaying hardwood logs and tree trunks',
            ],
            [
                'name' => 'Phoenix Oyster',
                'scientific_name' => 'Pleurotus pulmonarius',
                'classification' => 'edible',
                'description' => 'Also known as the Indian Oyster or Phoenix Mushroom, a pale, warm-weather oyster mushroom that is choice edible.',
                'habitat' => 'Subtropical and tropical decaying hardwood logs',
            ],
            [
                'name' => 'Rustgill',
                'scientific_name' => 'Gymnopilus',
                'classification' => 'poisonous',
                'description' => 'A genus of gilled mushrooms with rusty-orange spore prints. Many species are bitter and several contain neuroactive or toxic compounds.',
                'habitat' => 'Dead wood, stumps, and decaying palm or hardwood logs',
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
