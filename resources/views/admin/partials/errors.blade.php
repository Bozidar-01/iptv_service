@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <x-icon name="alert" />
        <div class="alert-body">
            Please fix the following:
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
