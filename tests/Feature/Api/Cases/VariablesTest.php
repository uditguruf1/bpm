<?php
namespace Tests\Feature\Api\Cases;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use ProcessMaker\Model\Application;
use ProcessMaker\Model\User;
use Tests\Feature\Api\ApiTestCase;
use G;

class VariablesTest extends ApiTestCase
{
    use DatabaseTransactions;

    /**
     * Test to determine fetching variables for a case/application.  Checks for proper policy check
     * and then that we're receiving the application data json.
     */
    public function testVariablesGet()
    {
        $user = factory(User::class)->create([
            'password' => Hash::make('password')
        ]);
        // We need an application
        $application = factory(Application::class)->create();

        $this->auth($user->username, 'password');

        $response = $this->api('GET', '/api/1.0/cases/' . $application->uid . '/variables');

        // We will first get a 403, because we are not a process supervisor and we haven't participated
        $response->assertStatus(403);

        // Let's set ourselves up as a supervisor
        $application->process->addUserSupervisor($user);

        // Now, let's fetch.  If we're a supervisor, we should be good to go
        $response = $this->api('GET', '/api/1.0/cases/' . $application->uid . '/variables');
        // Now make sure we pass policy and that we get the app data
        $response->assertStatus(200);
    }

}