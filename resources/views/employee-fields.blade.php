<label>Code (optional)<input name="code" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]*" placeholder="Auto-generated, e.g. E-0001"></label>
<label>Employment class<select name="classification">@foreach(['Regular', 'Probationary', 'Contractual', 'Part-time', 'Project-based', 'Seasonal'] as $classification)<option>{{ $classification }}</option>@endforeach</select></label>
<label class="masters-wide">Employee name <span class="masters-required">*</span><input name="name" required maxlength="150" autocomplete="name" placeholder="Full registered name"></label>
<label>TIN<input name="tin" maxlength="17" placeholder="000-000-000-00000"><small>Optional. Use the employee’s TIN with a five-digit branch code.</small></label>
<label>Date of birth<input name="birth_date" type="date" max="{{ now()->toDateString() }}"></label>
<label>Email<input name="email" type="email" maxlength="254" autocomplete="email"></label>
<label>Phone<input name="phone" maxlength="40" autocomplete="tel"></label>
<label class="masters-wide">Registered address<textarea name="address" rows="2" maxlength="500" autocomplete="street-address"></textarea><small>A non-placeholder TIN and address mark the profile Ready. Tax forms require a separate review.</small></label>
<label>Hire date<input name="hire_date" type="date"></label><label>End date<input name="end_date" type="date"></label>
<label>SSS no.<input name="sss_no" maxlength="30" autocomplete="off"></label><label>PhilHealth no.<input name="philhealth_no" maxlength="30" autocomplete="off"></label><label>Pag-IBIG no.<input name="pagibig_no" maxlength="30" autocomplete="off"></label>
