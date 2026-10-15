<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M10 «Проекты» — the actions of the project card (legacy prjCreate, prjSaveHead, prjExec*, prjCalcSave/Send, prjClientDecide,
 * prjUploadTo/prjDeleteFile, prjAddComment, prjDeleteProject). The list comes with the CRM page (CrmController). Rights are
 * checked by ProjectService/ProjectPolicy.
 */
class ProjectController extends Controller
{
    private const MONEY = 'regex:/^\d{1,13}(\.\d{1,2})?$/';

    private const QTY = 'regex:/^\d{1,9}(\.\d{1,3})?$/';

    public function __construct(private readonly ProjectService $service) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, [
            'title' => ['required', 'string', 'max:500'],
            'channel' => ['nullable', 'string', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ], ['title.required' => 'Введите название проекта']);
        $project = $this->service->create($data, $request->user());

        return back()->with('created', $project->id);
    }

    public function update(Request $request, int $project): RedirectResponse
    {
        $data = $this->validated($request, [
            'title' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(Project::STATUSES)],
            'order_num' => ['nullable', 'string', 'max:64'],
            'lock_version' => ['required', 'integer'],
        ]);
        if (($data['status'] ?? null) === null) {
            $data['status'] = Project::query()->whereKey($project)->value('status');
        }
        $this->service->updateHead($project, $data, (int) $data['lock_version'], $request->user());

        return back();
    }

    public function addExecutor(Request $request, int $project): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:64']]);
        $this->service->addExecutor($project, $data['login'], $request->user());

        return back();
    }

    public function removeExecutor(Request $request, int $project, string $login): RedirectResponse
    {
        $this->service->removeExecutor($project, $login, $request->user());

        return back();
    }

    public function saveCalc(Request $request, int $project): RedirectResponse
    {
        $data = $this->calc($request);
        $calc = $this->service->saveCalc($project, $data['version'] ?? null, $data['items'], $data['markup'] ?? null, (bool) ($data['as_new'] ?? false), $request->user());

        return back()->with('created', $calc->version);
    }

    public function sendCalc(Request $request, int $project): RedirectResponse
    {
        $data = $this->calc($request);
        $calc = $this->service->sendCalc($project, $data['version'] ?? null, $data['items'], $data['markup'] ?? null, $request->user());

        return back()->with('created', $calc->version);
    }

    public function decide(Request $request, int $project): RedirectResponse
    {
        $data = $request->validate(['accept' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $this->service->decide($project, (bool) $data['accept'], isset($data['reason']) ? trim($data['reason']) : null, $request->user());

        return back();
    }

    public function comment(Request $request, int $project): RedirectResponse
    {
        $data = $this->validated($request, ['text' => ['required', 'string', 'max:20000'], 'internal' => ['nullable', 'boolean']]);
        $this->service->addComment($project, $data['text'], (bool) ($data['internal'] ?? false), $request->user());

        return back();
    }

    public function upload(Request $request, int $project): RedirectResponse
    {
        $data = $request->validate([
            'side' => ['required', Rule::in(['customer', 'designer'])],
            'internal' => ['nullable', 'boolean'],
            'file' => ['required', 'file'],
        ], ['file.required' => 'Файл не получен — возможно, он больше 25 МБ.', 'file.file' => 'Файл не получен — возможно, он больше 25 МБ.']);
        $this->service->addFile($project, $data['side'], (bool) ($data['internal'] ?? false), $request->file('file'), $request->user());

        return back();
    }

    /** The content of a file: images are shown in place, anything else is downloaded (never rendered as a page). */
    public function file(Request $request, int $file): StreamedResponse
    {
        try {
            $row = $this->service->file($file, $request->user());
        } catch (ValidationException) {
            abort(404);
        }
        $disk = Storage::disk(ProjectService::DISK);
        abort_unless($disk->exists($row->storage_path), 404);
        $headers = ['Content-Type' => $row->isImage() ? $row->mime : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'", 'Cache-Control' => 'private, max-age=3600'];

        return $disk->response($row->storage_path, $row->file_name, $headers, $row->isImage() ? 'inline' : 'attachment');
    }

    public function deleteFile(Request $request, int $file): RedirectResponse
    {
        $this->service->deleteFile($file, $request->user());

        return back();
    }

    public function destroy(Request $request, int $project): RedirectResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);
        $this->service->delete($project, (int) $data['lock_version'], $request->user());

        return back();
    }

    /** @return array<string, mixed> */
    private function calc(Request $request): array
    {
        return $request->validate([
            'version' => ['nullable', 'integer', 'min:1'],
            'as_new' => ['nullable', 'boolean'],
            'markup' => ['nullable', 'regex:/^-?\d{1,6}(\.\d{1,2})?$/'],
            'items' => ['present', 'array', 'max:500'],
            'items.*.name' => ['nullable', 'string', 'max:1000'],
            'items.*.attrs' => ['nullable', 'string', 'max:2000'],
            'items.*.qty' => ['nullable', self::QTY],
            'items.*.cost' => ['nullable', self::MONEY],
            'items.*.price' => ['nullable', self::MONEY],
        ], [
            'items.*.qty.regex' => 'Количество — число не меньше 0, до 3 знаков после запятой.',
            'items.*.cost.regex' => 'Себестоимость — сумма не меньше 0, до 2 знаков после запятой.',
            'items.*.price.regex' => 'Цена — сумма не меньше 0, до 2 знаков после запятой.',
            'markup.regex' => 'Наценка — число, до 2 знаков после запятой.',
        ]);
    }

    /**
     * Trimmed text fields, empty → null.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules, array $messages = []): array
    {
        $request->merge(collect($request->only(array_keys($rules)))
            ->map(fn ($v) => is_string($v) ? (($t = trim($v)) === '' ? null : $t) : $v)->all());

        return $request->validate($rules, $messages);
    }
}
