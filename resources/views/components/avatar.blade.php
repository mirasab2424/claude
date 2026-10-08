@props(['user', 'size' => 48])
@if ($user->avatar)
    <img src="{{ Storage::disk('public')->url($user->avatar) }}" alt="" class="avatar" style="width: {{ $size }}px; height: {{ $size }}px">
@else
    <span class="avatar avatar_initials" style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ $size * 0.4 }}px" aria-hidden="true">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</span>
@endif
