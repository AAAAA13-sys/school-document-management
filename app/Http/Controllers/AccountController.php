<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AccountController extends Controller
{
    private function admin(Request $r): User
    {
        $a = app(DocumentService::class)->actor($r);
        abort_unless($a->role === 'admin', 403);

        return $a;
    }

    public function index(Request $r)
    {
        $a = $this->admin($r);
        $users = User::where('school_id', $a->school_id)->where('campus', $a->campus)->orderBy('name')->paginate(25);

        return view('accounts', compact('users'));
    }

    public function store(Request $r)
    {
        $a = $this->admin($r);
        $p = $r->validate([
            'name' => 'required|string|max:200', 'email' => 'required|email|max:200|unique:users,email',
            'role' => 'required|in:student,teacher,employee,admin,registrar,hr,payroll',
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        unset($p['password_confirmation']);
        DB::transaction(function () use ($a, $p) {
            $u = new User;
            $u->forceFill([...$p, 'school_id' => $a->school_id, 'campus' => $a->campus, 'active' => true])->save();
            app(DocumentService::class)->log($a, 'Account created', null, 'User '.$u->id.'; role '.$u->role);
        });

        return back()->with('status', 'Account created.');
    }

    public function update(Request $r, string $id)
    {
        $a = $this->admin($r);
        abort_if((string) $a->id === $id, 422, 'Use another administrator to change your own access.');
        $p = $r->validate(['role' => 'required|in:student,teacher,employee,admin,registrar,hr,payroll', 'active' => 'required|boolean']);
        DB::transaction(function () use ($a, $id, $p) {
            $u = User::where('school_id', $a->school_id)->where('campus', $a->campus)->whereKey($id)->lockForUpdate()->firstOrFail();
            $u->forceFill($p)->save();
            app(DocumentService::class)->log($a, 'Account access changed', null, 'User '.$u->id.'; role '.$u->role.'; active '.(int) $u->active);
        });

        return back()->with('status', 'Account access updated.');
    }
}
