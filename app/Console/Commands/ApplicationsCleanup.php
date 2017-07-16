<?php

namespace App\Console\Commands;

use App\Exceptions\VisitTransfer\Application\ApplicationCannotBeExpiredException;
use App\Models\VisitTransfer\Application;

class ApplicationsCleanup extends Command
{
    /**
     * The console command signature.
     *
     * The name of the command, along with any expected arguments.
     *
     * @var string
     */
    protected $signature = 'visittransfer:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up the applications in the VT system.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->cancelOldApplications();
        $this->runAutomatedChecks();
        $this->autoAcceptApplications();
        $this->autoCompleteNonTrainingApplications();
    }

    /**
     * Cancel any applications that have exceeded their expiry time.
     */
    private function cancelOldApplications()
    {
        foreach (Application::status(Application::STATUS_IN_PROGRESS)->get() as $application) {
            if ($application->expires_at->lt(\Carbon\Carbon::now())) {
                try {
                    $application->expire();
                } catch (ApplicationCannotBeExpiredException $e) {
                    // Shouldn't matter - maybe we were just late in catching them
                    continue;
                }
            }
        }
    }

    /**
     * If applicable, dispatch automated checks for submitted applications, otherwise progress them.
     */
    private function runAutomatedChecks()
    {
        $submittedApplications = Application::submitted()->get()->filter(function ($application) {
            return !$application->is_pending_references;
        });

        foreach ($submittedApplications as $application) {
            if ($application->should_perform_checks) {
                dispatch((new \App\Jobs\AutomatedApplicationChecks($application))->onQueue('med'));
            } else {
                $application->markAsUnderReview('Automated checks have been disabled for this facility - requires manual checking.');
            }
        }
    }

    /**
     * If an application under review can be accepted automatically, accept it.
     */
    private function autoAcceptApplications()
    {
        $underReviewApplications = Application::underReview()->where('will_auto_accept', 1)->get();

        foreach ($underReviewApplications as $application) {
            $application->accept('Application was automatically accepted as per the facility settings.');
        }
    }

    /**
     * If an accepted application doesn't require training, complete it.
     */
    private function autoCompleteNonTrainingApplications()
    {
        $acceptedApplications = Application::status(Application::STATUS_ACCEPTED)->get()
            ->filter(function ($application) {
                return !$application->training_required;
            });

        foreach ($acceptedApplications as $application) {
            $application->complete('Application was automatically completed as there is no training requirement.');
        }
    }
}
