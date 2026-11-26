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
                'description' => 'Termitomyces is a genus of basidiomycete fungi belonging to the family Lyophyllaceae. They are a food source for Macrotermitinae, a subfamily of fungus-growing termites, and are prized as choice edible mushrooms across Asia and Africa.',
                'habitat' => 'Grows symbiotically out of subterranean termite mounds in tropical grasslands and forests.',
            ],
            [
                'name' => 'Talingang Daga',
                'scientific_name' => 'Auricularia',
                'classification' => 'edible',
                'description' => 'Auricularia is a genus of fungi in the family Auriculariaceae. Fruit bodies are gelatinous, ear-like to cup-shaped, and grow on wood, where they are saprotrophic on dead and decaying branches.',
                'habitat' => 'Dead and decaying branches, logs, and moist rotting wood.',
            ],
            [
                'name' => 'Destroying Angel',
                'scientific_name' => 'Amanita virosa',
                'classification' => 'poisonous',
                'description' => 'Amanita virosa, commonly known in Europe as the destroying angel, is a deadly poisonous basidiomycete fungus in the genus Amanita. Its white fruit bodies contain amatoxins that cause severe liver and kidney failure.',
                'habitat' => 'Mixed woodlands and soil near trees.',
            ],
            [
                'name' => 'Flowerpot Parasol',
                'scientific_name' => 'Leucocoprinus birnbaumii',
                'classification' => 'poisonous',
                'description' => 'Leucocoprinus birnbaumii, commonly known as the plantpot dapperling or yellow houseplant mushroom, is a species of gilled mushroom in the family Agaricaceae. It is common in the tropics and subtropics and frequently appears in potted plants and greenhouses.',
                'habitat' => 'Potted houseplants, greenhouses, compost piles, and rich garden soil.',
            ],
            [
                'name' => 'Oyster Mushroom',
                'scientific_name' => 'Pleurotus ostreatus',
                'classification' => 'edible',
                'description' => 'Pleurotus ostreatus, the oyster mushroom, pearl oyster mushroom, or hiratake, is a common edible mushroom. It is one of the more commonly sought wild mushrooms, though it can also be cultivated on straw and other media.',
                'habitat' => 'Decaying hardwood logs, dying deciduous trees, and fallen timber.',
            ],
            [
                'name' => 'Phoenix Oyster',
                'scientific_name' => 'Pleurotus pulmonarius',
                'classification' => 'edible',
                'description' => 'Pleurotus pulmonarius, commonly known as the Indian Oyster, Italian Oyster, or Phoenix Mushroom, is a fungus very similar to Pleurotus ostreatus (the pearl oyster), but with a paler, thinner cap and a preference for warmer weather.',
                'habitat' => 'Grows in overlapping clusters on decaying hardwood logs and stumps in subtropical and tropical forests.',
            ],
            [
                'name' => 'Rustgill',
                'scientific_name' => 'Gymnopilus',
                'classification' => 'poisonous',
                'description' => 'Gymnopilus is a genus of gilled mushrooms within the fungal family Hymenogastraceae, characterized by rusty-orange spore prints, yellow to reddish-brown caps, and an often bitter taste.',
                'habitat' => 'Dead wood, decaying hardwood or palm logs, and tree stumps.',
            ],
            [
                'name' => 'Buna Shimeji',
                'scientific_name' => 'Hypsizygus tessellatus',
                'classification' => 'edible',
                'description' => 'Hypsizygus tessellatus, commonly known as Buna-shimeji or the beech mushroom, is an edible mushroom native to East Asia. It grows in dense clusters with small, marbled brownish-white caps and firm white stems.',
                'habitat' => 'Decaying beech and other hardwood trees, logs, and stumps.',
            ],
            [
                'name' => "Reishi / Artist's Conk",
                'scientific_name' => 'Ganoderma',
                'classification' => 'poisonous',
                'description' => 'Ganoderma is a genus of polypore bracket fungi in the family Ganodermataceae. Its tough, woody, shelf-like fruiting bodies often display a shiny, varnished reddish-brown surface and are inedible as culinary mushrooms.',
                'habitat' => 'Trunks, roots, and decaying stumps of living or dead hardwood and coniferous trees.',
            ],
            [
                'name' => 'Turkey Tail Bracket',
                'scientific_name' => 'Trametes',
                'classification' => 'poisonous',
                'description' => 'Trametes is a genus of leathery, fan-shaped polypore fungi that grow in overlapping tiers on wood. Their thin, tough, corky flesh makes them non-edible as a culinary mushroom.',
                'habitat' => 'Fallen hardwood logs, dead branches, and decaying tree stumps.',
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
