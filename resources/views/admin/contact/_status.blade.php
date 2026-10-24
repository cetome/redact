{{-- Delivery of a contact message to the cetome inbox: sent, failed (after the last attempt) or queued. --}}
@if ($item->sent_at)
    <span class="a-badge a-badge-success">{{ __('contact.admin.sent') }}</span>
@elseif ($item->failure)
    <span class="a-badge a-badge-danger" title="{{ $item->failure }}">{{ __('contact.admin.failed') }}</span>
@else
    <span class="a-badge a-badge-warning">{{ __('contact.admin.queued') }}</span>
@endif
