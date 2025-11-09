extends Control

@onready var name_input: LineEdit = $ScrollContainer/VBoxContainer/AddTaskPanel/AddTaskVBox/NameBox/NameInput
@onready var group_input: LineEdit = $ScrollContainer/VBoxContainer/AddTaskPanel/AddTaskVBox/GroupBox/GroupInput
@onready var schedule_type: OptionButton = $ScrollContainer/VBoxContainer/AddTaskPanel/AddTaskVBox/ScheduleBox/ScheduleType
@onready var times_box: HBoxContainer = $ScrollContainer/VBoxContainer/AddTaskPanel/AddTaskVBox/TimesPerWeekBox
@onready var times_input: SpinBox = $ScrollContainer/VBoxContainer/AddTaskPanel/AddTaskVBox/TimesPerWeekBox/TimesInput
@onready var difficulty_option: OptionButton = $ScrollContainer/VBoxContainer/AddTaskPanel/AddTaskVBox/DifficultyBox/DifficultyOption
@onready var add_task_button: Button = $ScrollContainer/VBoxContainer/AddTaskPanel/AddTaskVBox/AddTaskButton
@onready var today_tasks_container: VBoxContainer = $ScrollContainer/VBoxContainer/TodayTasks
@onready var weekly_tasks_container: VBoxContainer = $ScrollContainer/VBoxContainer/WeeklyTasks
@onready var times_tasks_container: VBoxContainer = $ScrollContainer/VBoxContainer/TimesTasks

var _difficulty_values := [5, 10, 15, 20]
var _schedule_types := {
    "Daily": "daily",
    "Weekly": "weekly",
    "X times/week": "times_per_week"
}

func _ready() -> void:
    _setup_schedule_options()
    _setup_difficulty_options()
    add_task_button.pressed.connect(_on_add_task_button_pressed)
    schedule_type.item_selected.connect(_on_schedule_selected)
    GameState.task_completed.connect(_on_task_completed)
    GameState.xp_changed.connect(_on_state_changed)
    _refresh_task_lists()

func _setup_schedule_options() -> void:
    schedule_type.clear()
    for label in ["Daily", "Weekly", "X times/week"]:
        schedule_type.add_item(label)
        schedule_type.set_item_metadata(schedule_type.item_count - 1, _schedule_types[label])
    schedule_type.select(0)
    _on_schedule_selected(0)

func _setup_difficulty_options() -> void:
    difficulty_option.clear()
    for i in range(_difficulty_values.size()):
        var xp := _difficulty_values[i]
        var label := "%d XP" % xp
        difficulty_option.add_item(label)
        difficulty_option.set_item_metadata(i, xp)
    difficulty_option.select(1)

func _on_schedule_selected(index: int) -> void:
    var meta = schedule_type.get_item_metadata(index)
    times_box.visible = meta == "times_per_week"

func _on_add_task_button_pressed() -> void:
    var task_name := name_input.text.strip_edges()
    if task_name.is_empty():
        name_input.grab_focus()
        return
    var selected_index := schedule_type.get_selected_id()
    var schedule_key := schedule_type.get_item_metadata(selected_index)
    var difficulty_index := difficulty_option.get_selected_id()
    var xp_value: int = difficulty_option.get_item_metadata(difficulty_index)
    var times_target := int(times_input.value)
    GameState.add_task(task_name, xp_value, schedule_key, times_target, group_input.text.strip_edges())
    name_input.clear()
    group_input.clear()
    times_input.value = max(times_input.min_value, 3)
    schedule_type.select(0)
    _on_schedule_selected(0)
    _refresh_task_lists()

func _on_task_completed(_task_id: int) -> void:
    _refresh_task_lists()

func _on_state_changed(_value: int) -> void:
    _refresh_task_lists()

func _refresh_task_lists() -> void:
    _populate_today_tasks()
    _populate_weekly_overview()
    _populate_times_tasks()

func _populate_today_tasks() -> void:
    _clear_container(today_tasks_container)
    var today_tasks := GameState.get_today_tasks()
    for task in today_tasks:
        var completed_today := GameState.is_task_completed_today(task)
        var info_text := "Group: %s" % task.group if not task.group.is_empty() else ""
        if task.schedule_type == "times_per_week":
            var progress := GameState.get_times_per_week_progress(task)
            info_text = "%d/%d this week" % [progress, max(task.times_per_week_target, 1)]
        elif task.schedule_type == "weekly":
            var weekly_done := GameState.is_task_completed_this_week(task)
            info_text = weekly_done ? "Completed this week" : "Still to do this week"
        var panel := _create_task_entry(task, info_text, not completed_today)
        today_tasks_container.add_child(panel)
    if today_tasks.is_empty():
        today_tasks_container.add_child(_create_empty_label("No tasks yet. Create one above!"))

func _populate_weekly_overview() -> void:
    _clear_container(weekly_tasks_container)
    var weekly_tasks := GameState.get_weekly_tasks()
    for task in weekly_tasks:
        var completed := GameState.is_task_completed_this_week(task)
        var status := completed ? "Done for this week" : "Pending"
        var panel := _create_summary_entry(task, status)
        weekly_tasks_container.add_child(panel)
    if weekly_tasks.is_empty():
        weekly_tasks_container.add_child(_create_empty_label("No weekly tasks yet."))

func _populate_times_tasks() -> void:
    _clear_container(times_tasks_container)
    var times_tasks := GameState.get_times_per_week_tasks()
    for task in times_tasks:
        var progress := GameState.get_times_per_week_progress(task)
        var target := max(task.times_per_week_target, 1)
        var status := "%d / %d" % [progress, target]
        if progress >= target:
            status += " - Goal reached!"
        var panel := _create_summary_entry(task, status)
        times_tasks_container.add_child(panel)
    if times_tasks.is_empty():
        times_tasks_container.add_child(_create_empty_label("No X/week tasks yet."))

func _create_task_entry(task: Task, info_text: String, can_complete: bool) -> Control:
    var panel := PanelContainer.new()
    panel.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    panel.size_flags_vertical = Control.SIZE_SHRINK_CENTER
    var margin := MarginContainer.new()
    margin.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    margin.size_flags_vertical = Control.SIZE_EXPAND_FILL
    margin.add_theme_constant_override("margin_left", 12)
    margin.add_theme_constant_override("margin_right", 12)
    margin.add_theme_constant_override("margin_top", 8)
    margin.add_theme_constant_override("margin_bottom", 8)
    panel.add_child(margin)

    var hbox := HBoxContainer.new()
    hbox.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    hbox.alignment = BoxContainer.ALIGNMENT_CENTER
    margin.add_child(hbox)

    var text_vbox := VBoxContainer.new()
    text_vbox.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    hbox.add_child(text_vbox)

    var name_label := Label.new()
    name_label.text = task.name
    name_label.theme_override_font_sizes["font_size"] = 20
    text_vbox.add_child(name_label)

    var detail_label := Label.new()
    var xp_info := "%d XP" % task.difficulty_xp
    if info_text.is_empty():
        detail_label.text = xp_info
    else:
        detail_label.text = "%s • %s" % [xp_info, info_text]
    detail_label.modulate = Color(0.3, 0.3, 0.3, 1)
    text_vbox.add_child(detail_label)

    var complete_button := Button.new()
    complete_button.text = can_complete ? "Complete" : "Done"
    complete_button.disabled = not can_complete
    complete_button.size_flags_horizontal = Control.SIZE_SHRINK_CENTER
    complete_button.pressed.connect(_on_complete_pressed.bind(task.id))
    hbox.add_child(complete_button)

    return panel

func _create_summary_entry(task: Task, info_text: String) -> Control:
    var panel := PanelContainer.new()
    panel.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    panel.size_flags_vertical = Control.SIZE_SHRINK_CENTER
    var margin := MarginContainer.new()
    margin.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    margin.size_flags_vertical = Control.SIZE_EXPAND_FILL
    margin.add_theme_constant_override("margin_left", 12)
    margin.add_theme_constant_override("margin_right", 12)
    margin.add_theme_constant_override("margin_top", 8)
    margin.add_theme_constant_override("margin_bottom", 8)
    panel.add_child(margin)

    var vbox := VBoxContainer.new()
    vbox.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    margin.add_child(vbox)

    var name_label := Label.new()
    name_label.text = task.name
    name_label.theme_override_font_sizes["font_size"] = 20
    vbox.add_child(name_label)

    var detail_label := Label.new()
    var group_info := task.group.is_empty() ? "" : "%s • " % task.group
    detail_label.text = "%s%d XP • %s" % [group_info, task.difficulty_xp, info_text]
    detail_label.modulate = Color(0.3, 0.3, 0.3, 1)
    vbox.add_child(detail_label)

    return panel

func _create_empty_label(text: String) -> Label:
    var label := Label.new()
    label.text = text
    label.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
    label.modulate = Color(0.35, 0.35, 0.35)
    return label

func _clear_container(container: Node) -> void:
    for child in container.get_children():
        child.queue_free()

func _on_complete_pressed(task_id: int) -> void:
    GameState.complete_task_for_today(task_id)
