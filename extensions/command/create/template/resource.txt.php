namespace {:namespace};

class {:class} extends {:parent} {

	public function index($request, ${:plural}) {
		return ${:plural};
	}

	public function add($request, ${:singular}) {
		return ($data = $request->data) ? ${:singular}->save($data) : ${:singular};
	}

	public function view($request, ${:singular}) {
		return ${:singular};
	}

	public function edit($request, ${:singular}) {
		return ($data = $request->data) ? ${:singular}->save($data) : ${:singular};
	}

	public function delete($request, ${:singular}) {
		return ${:singular}->delete();
	}
}
