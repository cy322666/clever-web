<div>
    @if (session()->has('success'))
        <div class="p-4 mb-4 text-sm text-green-800 bg-green-100 rounded-lg" role="alert">
            {{ session('success') }}
        </div>
    @endif

    <form wire:submit="create">
        {{ $this->form }}

        <button type="submit" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            Отправить
        </button>
    </form>
</div>
