<?php
namespace App\Livewire\Traveler\Concerns;

use App\Models\AiConversationHistory;

trait HandlesConversationHistory
{
    public bool $showHistory       = false;
    public ?int $viewingHistoryId  = null;
    public ?int $historyEntryToDelete = null;

    public function getConversationHistoryProperty()
    {
        return AiConversationHistory::where('user_id', auth()->id())
            ->latest()
            ->get();
    }

    public function getViewingHistoryEntryProperty()
    {
        if ($this->viewingHistoryId === null) return null;

        return AiConversationHistory::where('user_id', auth()->id())
            ->find($this->viewingHistoryId);
    }

    public function openHistory(): void
    {
        $this->showHistory = true;
    }

    public function closeHistory(): void
    {
        $this->showHistory      = false;
        $this->viewingHistoryId = null;
    }

    public function viewHistoryEntry(int $id): void
    {
        $this->showHistory      = true;
        $this->viewingHistoryId = $id;
    }

    public function backToHistoryList(): void
    {
        $this->viewingHistoryId = null;
    }

    public function confirmDeleteHistoryEntry(int $id): void
    {
        $this->historyEntryToDelete = $id;
    }

    public function cancelDeleteHistoryEntry(): void
    {
        $this->historyEntryToDelete = null;
    }

    public function deleteHistoryEntry(): void
    {
        if (!$this->historyEntryToDelete) return;

        AiConversationHistory::where('user_id', auth()->id())
            ->where('id', $this->historyEntryToDelete)
            ->delete();

        if ($this->viewingHistoryId === $this->historyEntryToDelete) {
            $this->viewingHistoryId = null;
        }

        $this->historyEntryToDelete = null;
    }
}
