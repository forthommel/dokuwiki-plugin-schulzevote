jQuery("#poll_table .childgrid tr").draggable({
    helper: function(){
        var selected = jQuery('.childgrid tr.selectedRow');
        if (selected.length === 0) {
            selected = jQuery(this).addClass('selectedRow');
        }
        var container = jQuery('<div/>').attr('id', 'draggingContainer');
        container.append(selected.clone().removeClass("selectedRow"));
        return container;
    }
});

jQuery("#poll_table .childgrid").droppable({
    drop: function (event, ui) {
        jQuery(this).append(ui.helper.children());
        jQuery('.selectedRow').remove();
    }
});

jQuery(document).on("click", ".childgrid tr", function () {
    jQuery(this).toggleClass("selectedRow");
});
