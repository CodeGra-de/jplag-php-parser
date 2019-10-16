.PHONY: get_node_types
get_node_types:
	rg '^class' vendor/microsoft/tolerant-php-parser/src/Node/ | sed 's/[^ ]*:class \([^ ]\+\).*/\1/g' -

install_deps:
	composer install
