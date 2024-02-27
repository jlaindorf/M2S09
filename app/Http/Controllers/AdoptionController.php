<?php

namespace App\Http\Controllers;

use App\Mail\SendDocuments;
use App\Mail\SendWelcomePet;
use App\Models\Adoption;
use App\Models\Client;
use App\Models\File;
use App\Models\People;
use App\Models\Pet;
use App\Traits\HttpResponses;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class AdoptionController extends Controller
{
    use HttpResponses;

    public function store(Request $request)
    {

        try {
            $data = $request->all();

            $request->validate([
                'name' => 'string|required|max:255',
                'contact' => 'string|required|max:20',
                'email' => 'string|required',
                'cpf' => 'string|required',
                'observations' => 'string|required',
                'pet_id' => 'integer|required',
            ]);

            $adoption = Adoption::create([...$data, 'status' => 'PENDENTE']);
            return $adoption;
        } catch (\Exception $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    public function index(Request $request)
    {
        try {

            // pegar os dados que foram enviados via query params
            $filters = $request->query();

            // inicializa uma query
            $pets = Pet::query()
                ->select(
                    'id',
                    'pets.name as pet_name',
                    'pets.age as age'
                )
                #->with('race') // traz todas as colunas
                ->where('client_id', null);


            // verifica se filtro
            if ($request->has('name') && !empty($filters['name'])) {
                $pets->where('name', 'ilike', '%' . $filters['name'] . '%');
            }

            if ($request->has('age') && !empty($filters['age'])) {
                $pets->where('age', $filters['age']);
            }

            if ($request->has('size') && !empty($filters['size'])) {
                $pets->where('size', $filters['size']);
            }

            if ($request->has('weight') && !empty($filters['weight'])) {
                $pets->where('weight', $filters['weight']);
            }

            if ($request->has('specie_id') && !empty($filters['specie_id'])) {
                $pets->where('specie_id', $filters['specie_id']);
            }

            return $pets->orderBy('created_at', 'desc')->get();
        } catch (\Exception $exception) {
            return $this->error($exception->getMessage(), Response::HTTP_BAD_REQUEST);
        }
    }

    public function show($id)
    {
        $pet = Pet::with("race")->with("specie")->find($id);

        if ($pet->client_id) return $this->error('Dados confidenciais', Response::HTTP_FORBIDDEN);

        if (!$pet) return $this->error('Dado não encontrado', Response::HTTP_NOT_FOUND);

        return $pet;
    }
    public function getAdoptions()
    {
        $adoptions = Adoption::query()->with('pet')->get();
        return $adoptions;
    }

    public function approve(Request $request)
    {
        //ATUALIZA O STATUS DE ADOÇÃO PARA APROVADO
        $data = $request->all();

        $request->validate([
            'adoption_id' => 'integer|required',
        ]);

        $adoption = Adoption::find($data['adoption_id']);

        if (!$adoption) return $this->error('Dado não encontrado', Response::HTTP_NOT_FOUND);

        // EFETIVO O CADASTRO DA PESSOA QUE TEM INTENÇÃO DE ADOTAR NO SISTEMA
        $adoption->update(['status' => 'APROVADO']);
        $adoption->save();

        $people = People::create([
            'name' => $adoption->name,
            'email' => $adoption->email,
            'contact' => $adoption->contact,
            'cpf' => $adoption->cpf
        ]);

        $client = Client::create([
            'people_id' => $people->id,
            'bonus' => true
        ]);

        // VINCULA O PET AO CLIENTE CRIADO E RETIRA ELE DA OPÇÃO DE PETS PARA SEREM ADOTADOS
        $pet = Pet::find($adoption->pet_id);
        $pet->update(['client_id' => $client->id]);
        $pet->save();


        Mail::to($people->email, $people->name)
            ->send(new SendDocuments($people->name));

        return $client;
    }
    public function upload(Request $request)
    {
        $file = $request->file('file');
        $description =  $request->input('description');

        $slugName = Str::of($description)->slug();

        $fileName = $slugName . '.' . $file->extension();

        $pathBucket = Storage::disk('s3')->put('documentos', $file);

        $fullPathFile = Storage::disk('s3')->url($pathBucket);

       $fileCreated = File::create(
            [
                'name' => $fileName,
                'size' => $file->getSize(),
                'mime' => $file->extension(),
                'url' => $fullPathFile
            ]
        );

        return [
            'message' => 'Arquivo criado com sucesso'
        ];
    }
}
