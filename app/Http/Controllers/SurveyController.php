<?php

namespace App\Http\Controllers;

use App\Http\Requests\SurveyStoreRequest;
use App\Http\Requests\SurveyUdateRequest;
use App\Http\Resources\SurveyResource;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

class SurveyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        return
        SurveyResource::collection(
        Survey::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SurveyStoreRequest $request)
    {
        $data = $request->validated();


        if (isset($data['image'])) {
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;
        }

        $survey = Survey::create($data);

        //Creaete questions
        foreach ($data['questions'] as $question) {
            $question['survey_id'] = $survey->id;
            $this->createQuestion($question);
        }
        return new SurveyResource($survey);
    }

    /**
     * Display the specified resource.
     */
    public function show(Survey $survey, Request $request)
    {
        $user = $request->user();
        if($survey->user_id !== $user->id){
            return abort(403, 'Unauthorized action');
        }

        return new SurveyResource($survey);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SurveyUdateRequest $request, Survey $survey)
    {
        $data = $request->validated();

        //check if image is was given and save on local file system

        if (isset($data['image'])) {
            $relativePath = $this->saveImage($data['image']);
            $data['image'] = $relativePath;
        }

        //if there is an old image delete it
        if($survey->image){
            $absolutePath = public_path($survey->image);
            File::delete($absolutePath);
        }
        //Update survey in the database
        $survey->update($data);

        //Get ids as plain array from questions
        $exisitingIds = $survey->questions->pluck('id')->toArray();
        //Get ids as plain array of new questions
        $newIds = Arr::pluck($data['questions'], 'id');

        //Delete questions that are not in the new array
        $questionsToDelete = array_diff($exisitingIds, $newIds);
        //Find questions to add
        $questionsToAdd = array_diff($newIds, $exisitingIds);

        //Delete questions by $questionsToDelete array
        SurveyQuestion::destroy($questionsToDelete);

        //Add new questions
        foreach ($data['questions'] as $question) {
            if(in_array($question['id'], $questionsToAdd)){
                $question['survey_id'] = $survey->id;
                $this->createQuestion($question);
            }
        }

        //Update existing questions
        $questionMap = collect($data['questions'])->keyBy('id');
        foreach ($survey->questions as $question) {
            if(isset($questionMap[$question->id])){
                $this->updateQuestion($question, $questionMap[$question->id]);
            }
        }
        return new SurveyResource($survey);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Survey $survey, Request $request)
    {
        $user = $request->user();
        if($survey->user_id !== $user->id){
            return abort(403, 'Unauthorized action');
        }

        $survey->delete();
        //Delete image if exists
        if($survey->image){
            $absolutePath = public_path($survey->image);
            File::delete($absolutePath);
        }

        return response('', 204);

    }

    /**
     * Save image to storage and return relative path
     */
    private function saveImage($image){
        //check if image is base64 string
        if (preg_match('/^data:image\/(\w+);base64,/', $image, $type)) {
            //Take out the base64 encoded text without mime type
            $image = substr($image, strpos($image, ',') + 1);
            //GEt file exgtension
            $type = strtolower($type[1]);
            //check if image is valid
            if (!in_array($type, [ 'jpg', 'jpeg', 'gif', 'png' ])) {
                throw new \Exception('Invalid image type');
            }
            $image =str_replace(' ', '+', $image);
            $image = base64_decode($image);
            if ($image === false) {
                throw new \Exception('base64_decode failed');
            }
        }else{
            throw new \Exception('Did not match data URI with image data');
        }

        $dir = 'images/';
        $file = Str::random() . '.' . $type;
        $absolutePath = public_path($dir);
        $relativePath = $dir . $file;
        if (!File::exists($absolutePath)) {
            File::makeDirectory($absolutePath, 0755, true);
        }

        file_put_contents($relativePath, $image);
        return $relativePath;


    }

    /**
     * Create question
     */
    private function createQuestion($data){
        if(is_array($data['data'])){
            $data['data'] = json_encode($data['data']);
        }
        $validator = Validator::make($data, [
            'question' => 'required|string',
            'type' => ['required', new Enum(QuestionTypeEnum::class)],
            'data' => 'present',
            'description' => 'nulllable|string',
            'survey_id' => 'exists:App\Models\Survey,id'
        ]);

        return SurveyQuestion::create($validator->validated());
    }

    /**
     * Update question
     */
    private function updateQuestion(SurveyQuestion $question, $data){
        if(is_array($data['data'])){
            $data['data'] = json_encode($data['data']);
        }
        $validator = Validator::make($data, [
            'id' => 'exists:App\Models\SurveyQuestion,id',
            'question' => 'required|string',
            'type' => ['required', new Enum(QuestionTypeEnum::class)],
            'data' => 'present',
            'description' => 'nulllable|string',
        ]);

        return $question->update($validator->validated());
    }
}
