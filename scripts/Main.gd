extends Control

@onready var tab_container: TabContainer = $VBoxContainer/TabContainer
@onready var tasks_tab: MarginContainer = $VBoxContainer/TabContainer/TasksTab
@onready var sanctuary_tab: MarginContainer = $VBoxContainer/TabContainer/SanctuaryTab

func _ready() -> void:
    tab_container.set_tab_title(0, "Tasks")
    tab_container.set_tab_title(1, "Sanctuary")
    _populate_tabs()

func _populate_tabs() -> void:
    if tasks_tab.get_child_count() == 0:
        var tasks_view: Control = load("res://scenes/TasksView.tscn").instantiate()
        tasks_tab.add_child(tasks_view)
        tasks_view.set_anchors_preset(Control.PRESET_FULL_RECT)
    if sanctuary_tab.get_child_count() == 0:
        var sanctuary_view: Control = load("res://scenes/Sanctuary.tscn").instantiate()
        sanctuary_tab.add_child(sanctuary_view)
        sanctuary_view.set_anchors_preset(Control.PRESET_FULL_RECT)
