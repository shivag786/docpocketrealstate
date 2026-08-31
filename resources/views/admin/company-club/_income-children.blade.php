{{-- The children of one node, rendered for an AJAX "load more". --}}
@foreach ($children as $child)
    @include('admin.company-club._income-node', ['node' => $child])
@endforeach
