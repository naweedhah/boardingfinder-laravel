<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoommateProfileRequest extends FormRequest
{
    public const CITIES = [
        'Colombo', 'Kandy', 'Galle', 'Jaffna', 'Kurunegala',
        'Matara', 'Negombo', 'Anuradhapura', 'Ratnapura', 'Badulla',
    ];

    public const UNIVERSITIES = [
        'University of Peradeniya', 'University of Colombo', 'University of Moratuwa',
        'University of Kelaniya', 'University of Sri Jayewardenepura', 'University of Ruhuna',
        'University of Jaffna', 'Rajarata University of Sri Lanka',
        'Sabaragamuwa University of Sri Lanka', 'Wayamba University of Sri Lanka',
        'Eastern University, Sri Lanka', 'South Eastern University of Sri Lanka',
        'Uva Wellassa University', 'NSBM Green University', 'SLIIT', 'IIT Sri Lanka',
    ];

    public const SLEEP_SCHEDULES = ['Sleeps before midnight', 'Late sleeper', 'Early riser'];

    public const STUDY_HABITS = ['Quiet evenings', 'Group study', 'Flexible'];

    public const FOOD_PREFERENCES = [
        'Vegetarian-friendly', 'No special preference', 'Halal-friendly', 'Vegan-friendly',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** Blank form fields and "true"/"false" strings arrive from the browser form. */
    protected function prepareForValidation(): void
    {
        $data = [];

        foreach ($this->all() as $key => $value) {
            $data[$key] = $value === '' ? null : $value;
        }

        foreach (['smokingAllowed', 'petsAllowed'] as $flag) {
            $data[$flag] = match ($data[$flag] ?? null) {
                'true' => true,
                'false' => false,
                true, false => $data[$flag],
                default => null,
            };
        }

        $this->replace($data);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'age' => ['bail', 'required', 'integer', 'between:16,60'],
            'university' => ['bail', 'required', Rule::in(self::UNIVERSITIES)],
            'faculty' => ['bail', 'required', 'string', 'min:2', 'max:255'],
            'yearOfStudy' => ['bail', 'required', 'string', 'max:255'],
            'budgetMin' => ['bail', 'required', 'integer', 'min:5000'],
            'budgetMax' => ['bail', 'required', 'integer', 'gte:budgetMin'],
            'preferredCity' => ['bail', 'required', Rule::in(self::CITIES)],
            'preferredArea' => ['bail', 'required', 'string', 'min:2', 'max:255'],
            'sleepSchedule' => ['bail', 'required', Rule::in(self::SLEEP_SCHEDULES)],
            'cleanlinessLevel' => ['bail', 'required', 'integer', 'between:1,5'],
            'studyHabit' => ['bail', 'required', Rule::in(self::STUDY_HABITS)],
            'smokingAllowed' => ['bail', 'required', 'boolean'],
            'petsAllowed' => ['bail', 'required', 'boolean'],
            'foodPreference' => ['bail', 'required', Rule::in(self::FOOD_PREFERENCES)],
            'sociabilityLevel' => ['bail', 'required', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $budget = 'Enter a valid budget range with max budget above min budget.';

        return [
            'age.*' => 'Age must be between 16 and 60.',
            'university.*' => 'Select a valid university.',
            'faculty.*' => 'Faculty must be at least 2 characters long.',
            'yearOfStudy.*' => 'Select your year of study.',
            'budgetMin.*' => $budget,
            'budgetMax.*' => $budget,
            'preferredCity.*' => 'Select a valid preferred city.',
            'preferredArea.*' => 'Preferred area must be at least 2 characters long.',
            'sleepSchedule.*' => 'Select a valid sleep schedule.',
            'cleanlinessLevel.*' => 'Cleanliness level must be between 1 and 5.',
            'studyHabit.*' => 'Select a valid study habit.',
            'smokingAllowed.*' => 'Select a smoking preference.',
            'petsAllowed.*' => 'Select a pets preference.',
            'foodPreference.*' => 'Select a valid food preference.',
            'sociabilityLevel.*' => 'Sociability level must be between 1 and 5.',
            'notes.max' => 'Notes must stay within 300 characters.',
        ];
    }
}
