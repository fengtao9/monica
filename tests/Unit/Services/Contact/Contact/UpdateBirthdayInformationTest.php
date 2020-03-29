<?php

namespace Tests\Unit\Services\Contact\Contact;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Contact\Contact;
use App\Models\Instance\SpecialDate;
use Illuminate\Support\Facades\Queue;
use App\Jobs\AuditLog\LogAccountAudit;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Services\Contact\Contact\UpdateBirthdayInformation;

class UpdateBirthdayInformationTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_deletes_all_birthday_information()
    {
        Queue::fake();

        // to delete birthday information, we need first to update the contact
        // with its birthday info, then update it again by indicating that
        // we don't know his birthday info
        $contact = factory(Contact::class)->create([]);
        $user = factory(User::class)->create([
            'account_id' => $contact->account_id,
        ]);

        $request = [
            'account_id' => $contact->account_id,
            'author_id' => $user->id,
            'contact_id' => $contact->id,
            'is_date_known' => true,
            'day' => 10,
            'month' => 10,
            'year' => 1980,
            'is_age_based' => false,
            'age' => 0,
            'add_reminder' => true,
        ];

        app(UpdateBirthdayInformation::class)->execute($request);

        $specialDate = SpecialDate::where('contact_id', $contact->id)->first();

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'account_id' => $contact->account_id,
            'birthday_special_date_id' => $specialDate->id,
        ]);

        $this->assertDatabaseHas('special_dates', [
            'id' => $specialDate->id,
            'account_id' => $contact->account_id,
            'is_age_based' => false,
        ]);

        // then we update it again
        $request = [
            'account_id' => $contact->account_id,
            'contact_id' => $contact->id,
            'author_id' => $user->id,
            'is_date_known' => false,
        ];

        $contact = app(UpdateBirthdayInformation::class)->execute($request);

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'account_id' => $contact->account_id,
            'birthday_special_date_id' => null,
        ]);

        // check that a job has been triggered to create an auditlog
        Queue::assertPushed(LogAccountAudit::class, function ($job) use ($contact, $user) {
            return $job->auditLog['action'] === 'contact_birthday_updated' &&
                $job->auditLog['author_id'] === $user->id &&
                $job->auditLog['about_contact_id'] === $contact->id &&
                $job->auditLog['should_appear_on_dashboard'] === true &&
                $job->auditLog['objects'] === json_encode([
                    'contact_name' => $contact->name,
                    'contact_id' => $contact->id,
                ]);
        });
    }

    /** @test */
    public function it_sets_a_date_if_age_is_provided()
    {
        $contact = factory(Contact::class)->create([]);
        $user = factory(User::class)->create([
            'account_id' => $contact->account_id,
        ]);

        $request = [
            'account_id' => $contact->account_id,
            'author_id' => $user->id,
            'contact_id' => $contact->id,
            'is_date_known' => true,
            'is_age_based' => true,
            'age' => 10,
        ];

        $contact = app(UpdateBirthdayInformation::class)->execute($request);

        $specialDate = SpecialDate::where('contact_id', $contact->id)->first();

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'account_id' => $contact->account_id,
            'birthday_special_date_id' => $specialDate->id,
        ]);

        $this->assertDatabaseHas('special_dates', [
            'id' => $specialDate->id,
            'account_id' => $contact->account_id,
            'is_age_based' => true,
        ]);
    }

    /** @test */
    public function it_sets_a_complete_date()
    {
        $contact = factory(Contact::class)->create([]);
        $user = factory(User::class)->create([
            'account_id' => $contact->account_id,
        ]);

        $request = [
            'account_id' => $contact->account_id,
            'author_id' => $user->id,
            'contact_id' => $contact->id,
            'is_date_known' => true,
            'day' => 10,
            'month' => 10,
            'year' => 1980,
            'is_age_based' => false,
            'add_reminder' => false,
        ];

        $contact = app(UpdateBirthdayInformation::class)->execute($request);

        $specialDate = SpecialDate::where('contact_id', $contact->id)->first();

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'account_id' => $contact->account_id,
            'birthday_special_date_id' => $specialDate->id,
        ]);

        $this->assertDatabaseHas('special_dates', [
            'id' => $specialDate->id,
            'account_id' => $contact->account_id,
            'is_age_based' => false,
            'is_year_unknown' => false,
        ]);
    }

    /** @test */
    public function it_sets_a_complete_date_and_sets_a_reminder()
    {
        $contact = factory(Contact::class)->create([]);
        $user = factory(User::class)->create([
            'account_id' => $contact->account_id,
        ]);

        $request = [
            'account_id' => $contact->account_id,
            'author_id' => $user->id,
            'contact_id' => $contact->id,
            'is_date_known' => true,
            'day' => 10,
            'month' => 10,
            'year' => 1980,
            'is_age_based' => false,
            'add_reminder' => true,
        ];

        $contact = app(UpdateBirthdayInformation::class)->execute($request);

        SpecialDate::where('contact_id', $contact->id)->first();

        $this->assertNotNull($contact->birthday_reminder_id);
    }

    /** @test */
    public function it_fails_if_wrong_parameters_are_given()
    {
        $contact = factory(Contact::class)->create([]);

        $request = [
            'account_id' => $contact->account_id,
            'contact_id' => $contact->id,
            'day' => 10,
            'month' => 10,
            'year' => 1980,
            'is_age_based' => false,
            'add_reminder' => false,
        ];

        $this->expectException(ValidationException::class);

        app(UpdateBirthdayInformation::class)->execute($request);
    }

    /** @test */
    public function it_throws_an_exception_if_contact_and_account_are_not_linked()
    {
        $contact = factory(Contact::class)->create([]);
        $user = factory(User::class)->create([
            'account_id' => $contact->account_id,
        ]);

        $request = [
            'account_id' => 11111111,
            'author_id' => $user->id,
            'contact_id' => $contact->id,
            'is_date_known' => true,
            'day' => 10,
            'month' => 10,
            'year' => 1980,
            'is_age_based' => false,
            'add_reminder' => false,
        ];

        $this->expectException(ValidationException::class);

        app(UpdateBirthdayInformation::class)->execute($request);
    }
}
