@extends('layouts.dashboard')

@section('page_heading')
匯入教師資訊
@endsection

@section('section')
<div class="container">
	<div class="row">
	@if (session('error'))
	    <div class="alert alert-danger">
		{{ session('error') }}
	    </div>
	@endif
	@if (session('success'))
	    <div class="alert alert-info">
	    @foreach (session('success') as $line)
		{{ $line }}<br>
		@endforeach
	    </div>
	@endif
	<div class="col-sm-12">
		<div class="panel panel-default">	  
		<div class="panel-heading">
			<h4>為什麼用 JSON?</h4>
		</div>
		<div class="panel-body">
			<div class="form-group">
			跟 EXCEL 比起來，JSON 的優點是格式開源，因此不用買授權。跟 XML 比起來，JSON 輕薄短小不佔磁碟空間，可以節省網路流量。跟 CSV 比起來，JSON 可以表徵陣列和物件，可以交換二進位檔案內容。
			JSON 有人用嗎？有的，事實上絕大多數的網站，只要有交換資料的需求，都會選擇 JSON！不會用 JSON？沒問題！任何格式都可以線上轉成 JSON，請自行 Google 使用。
			JSON 支援標準 UTF-8 格式，不支援微軟 UTF-8 BOM 格式，存檔時請務必挑選正確的儲存格式。
			</div>
		</div>
		</div>
		<div class="panel panel-default">	  
		<div class="panel-heading">
			<h4>教師資訊 JSON 格式範例</h4>
		</div>
		<div class="panel-body">
			<div class="form-group">
			{{ json_encode($sample1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}
			</div>
		</div>
		</div>
		<div class="panel panel-default">	  
		<div class="panel-heading">
			<h4>移除可省略欄位後之精簡範例</h4>
		</div>
		<div class="panel-body">
			<div class="form-group">
			{{ json_encode($sample2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}
			</div>
		</div>
		</div>
		<div class="panel panel-default">	  
		<div class="panel-heading">
			<h4>欄位格式說明</h4>
		</div>
		<div class="panel-body">
			<div class="form-group">
				<dl class="dl-horizontal">
					<dt>id</dt>
					<dd>身分證字號，是單一資料，使用 " " 括起來。</dd>
					<dt>account</dt>
					<dd>帳號，是單一資料。最少 6 個字元，只允許英文字母加數字。可省略。省略時以學校代號加身分證字號後九碼為預設帳號。</dd>
					<dt>password</dt>
					<dd>密碼，是單一資料。最少 6 個字元。可省略。省略時以身分證字號後六碼為預設密碼。</dd>
					<dt>ou</dt>
					<dd>組織單位代號，是單一資料。由各校自訂，只允許英文字母加數字。可省略。</dd>
					<dt>role</dt>
					<dd>職稱代號，是單一資料。由各校自訂，只允許英文字母加數字。可省略。</dd>
					<dt>assign</dt>
					<dd>任教班級及科目，班級代號與科目代號之間，使用 , 間隔，並用 " " 括起來。多筆時，資料與資料之間使用 , 間隔，最後在前後加上 [ ]。未安排課務時可省略。</dd>
					<dt>sn</dt>
					<dd>姓氏，是單一資料。</dd>
					<dt>gn</dt>
					<dd>名字，是單一資料。</dd>
					<dt>name</dt>
					<dd>全名，是單一資料。若省略，則由系統自動將姓氏與名字合併處理。</dd>
					<dt>gender</dt>
					<dd>性別，是單一資料。0 代表未知，1 代表男，2 代表女，9 代表其它。</dd>
					<dt>birthdate</dt>
					<dd>出生日期，是單一資料。請使用西元紀年，格式如：19911105。</dd>
					<dt>mail</dt>
					<dd>電子郵件，允許多筆資料，單一筆時，用 " " 括起來。多筆時請使用 " " 括住每筆資料，資料與資料之間使用 , 間隔，最後在前後加上 [ ]。可省略。</dd>
					<dt>mobile</dt>
					<dd>手機號碼，允許多筆資料，同上。格式如：0921000111。可省略。</dd>
					<dt>fax</dt>
					<dd>傳真電話，允許多筆資料，同上。格式如：(02)23093736。可省略。</dd>
					<dt>otel</dt>
					<dd>辦公電話，允許多筆資料，同上。可省略。</dd>
					<dt>htel</dt>
					<dd>住家電話，允許多筆資料，同上。可省略。</dd>
					<dt>register</dt>
					<dd>戶籍地址，是單一資料。請包含里鄰資訊。可省略。</dd>
					<dt>address</dt>
					<dd>郵寄地址，是單一資料。可省略。</dd>
					<dt>www</dt>
					<dd>個人網頁，是單一資料。可省略。</dd>
				</dl>
			</div>
		</div>
		</div>
		<div class="panel panel-default">	  
		<div class="panel-heading">
			<h4>批量匯入</h4>
		</div>
		<div class="panel-body">
			<div class="form-group">
			想要一次匯入多位老師嗎？請在每位老師的 JSON 字串中間使用 , 間隔，然後前後用 [ ] 括起來。例如：
			<p>
			[第一位老師的JSON字串,第二位老師的JSON字串,......省略.....,最後一位老師的JSON字串]
			</p>
			</div>
		</div>
		</div>
		<div class="panel panel-default">	  
		<div class="panel-heading">
			<h4>匯入 JSON</h4>
		</div>
		<div class="panel-body">
			<form role="form" method="POST" action="{{ route('school.jsonTeacher') }}" enctype="multipart/form-data">
		    	@csrf
			    <div class="form-group{{ $errors->has('json') ? ' has-error' : '' }}">
					<input id="json" type="file" class="form-control" name="json" required>
					@if ($errors->has('json'))
						<p class="help-block">
							<strong>{{ $errors->first('json') }}</strong>
						</p>
					@endif
				</div>
			    <div class="form-group">
					<button type="submit" class="btn btn-success">匯入</button>
				</div>
			</form>
		</div>
		</div>
	</div>
	</div>
</div>
@endsection
