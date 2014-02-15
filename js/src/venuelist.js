if (!Array.prototype.forEach) {
  Array.prototype.forEach = function(fn, scope) {
    for (var i = 0, len = this.length; i < len; ++i) {
      fn.call(scope || this, this[i], i, this);
    }
  }
}

venuelist = {
	"init": function(list, listelement, bindelement) {
		var t = this;

		this.list = list;
		this.initial = list.slice(0, 8).sort();
		this.listElem = $(listelement);
		this.bindElem = $(bindelement);

		this.populate(this.initial);

		// Set default value?
		this.listElem.children().each(function(i, e) {
			if (e.innerText == t.bindElem.val()) {
				t.select(e);
			}
		});

		var update = function() {
			t.deselect();
			t.show_only_with($(this).val().toLowerCase());
		};

		this.bindElem.keydown(update);
		this.bindElem.keyup(update);
	},

	"depopulate": function() {
		this.listElem.children().remove();
	},

	"populate": function(list) {
		var t = this;

		list.slice(0, 8).forEach(function(v) {
			t.listElem.append("<li>" + v + "</li>");
		});
	
		this.listElem.children().each(function(i, e) {
			$(e).on("click", function(event) {
				t.select(event.target);
			});
		});
	},

	"select" : function(elem) {
		this.deselect();
		this.listElem.addClass("selected");
		$(elem).addClass("selected");
		this.bindElem.val($(elem).text());
		if ($.validator)
			this.bindElem.valid();
	},

	"deselect": function() {
		this.listElem.removeClass("selected");
		this.listElem.children().each(function(i, e) {
			$(e).removeClass("selected");
		});
	},

	"show_only_with": function(chars) {
		var matches;

		if (chars == "") {
			matches = this.initial;
		} else {
			matches = [];
			this.list.forEach(function(value) {
				if (value.toLowerCase().indexOf(chars) != -1)
					matches.push(value);
			});
		}

		this.depopulate();
		this.populate(matches.sort());

		if (this.empty)
			this.empty(matches.length == 0);
	},
};
