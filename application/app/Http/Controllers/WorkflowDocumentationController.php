<?php

namespace App\Http\Controllers;

use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowDocumentationService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WorkflowDocumentationController extends Controller
{
    public function workflow(Workflow $workflow, WorkflowDocumentationService $documentation): Response
    {
        $this->authorizeWorkflow($workflow);

        return $this->pdf(
            $documentation->singleDocument($workflow),
            'scenario-' . $workflow->getKey() . '-documentation.pdf'
        );
    }

    public function account(WorkflowDocumentationService $documentation): Response
    {
        abort_unless(auth()->check(), 403);

        return $this->pdf(
            $documentation->accountDocument((int)auth()->id()),
            'scenarios-documentation.pdf'
        );
    }

    private function authorizeWorkflow(Workflow $workflow): void
    {
        $user = auth()->user();

        abort_unless($user, 403);

        if ((bool)($user->is_root ?? false)) {
            return;
        }

        abort_unless((int)$workflow->user_id === (int)$user->getKey(), 403);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function pdf(array $document, string $filename): Response
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);

        $previousErrorReporting = error_reporting();

        try {
            error_reporting($previousErrorReporting & ~E_DEPRECATED & ~E_USER_DEPRECATED);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml(view('filament.workflow-builder.documentation.pdf', [
                'document' => $document,
            ])->render(), 'UTF-8');
            $dompdf->setPaper('a4');
            $dompdf->render();
        } finally {
            error_reporting($previousErrorReporting);
        }

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . Str::ascii($filename) . '"',
        ]);
    }
}
