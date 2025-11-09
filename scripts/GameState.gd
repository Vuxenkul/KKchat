extends Node

signal xp_changed(new_xp: int)
signal dino_level_changed(new_level: int)
signal task_completed(task_id: int)

const SAVE_PATH := "user://save_game.json"
const LEVEL_THRESHOLDS := [0, 50, 150, 300]
const VALID_DIFFICULTIES := [5, 10, 15, 20]

var tasks: Array[Task] = []
var total_xp: int = 0
var dino_level: int = 1
var _next_task_id: int = 1

func _ready() -> void:
    load_state()

func add_task(name: String, difficulty_xp: int, schedule_type: String, times_per_week_target: int = 0, group: String = "") -> void:
    if not VALID_DIFFICULTIES.has(difficulty_xp):
        push_warning("Invalid difficulty xp supplied. Falling back to 5 XP.")
        difficulty_xp = 5
    if schedule_type not in ["daily", "weekly", "times_per_week"]:
        push_warning("Invalid schedule type supplied. Using daily instead.")
        schedule_type = "daily"
    var task := Task.new()
    task.id = _next_task_id
    task.name = name.strip_edges()
    task.difficulty_xp = difficulty_xp
    task.schedule_type = schedule_type
    task.times_per_week_target = max(times_per_week_target, 1) if schedule_type == "times_per_week" else 0
    task.group = group.strip_edges()
    task.completions = []
    tasks.append(task)
    _next_task_id += 1
    save_state()

func remove_task(id: int) -> void:
    for i in tasks.size():
        if tasks[i].id == id:
            tasks.remove_at(i)
            save_state()
            return

func get_task(id: int) -> Task:
    for task in tasks:
        if task.id == id:
            return task
    return null

func complete_task_for_today(id: int) -> void:
    var task := get_task(id)
    if task == null:
        return
    var today := _get_today_string()
    if task.is_completed_on_date(today):
        return
    task.completions.append(today)
    total_xp += task.difficulty_xp
    xp_changed.emit(total_xp)
    task_completed.emit(task.id)
    _update_dino_level_if_needed()
    save_state()

func get_today_tasks() -> Array[Task]:
    var today_tasks: Array[Task] = []
    for task in tasks:
        match task.schedule_type:
            "daily":
                today_tasks.append(task)
            "weekly":
                if not is_task_completed_this_week(task):
                    today_tasks.append(task)
            "times_per_week":
                if get_times_per_week_progress(task) < max(task.times_per_week_target, 1):
                    today_tasks.append(task)
            _:
                pass
    return today_tasks

func get_weekly_tasks() -> Array[Task]:
    return tasks.filter(func(t): return t.schedule_type == "weekly")

func get_times_per_week_tasks() -> Array[Task]:
    return tasks.filter(func(t): return t.schedule_type == "times_per_week")

func get_times_per_week_progress(task: Task) -> int:
    var count := 0
    for date_string in task.completions:
        if _is_date_in_current_week(date_string):
            count += 1
    return count

func is_task_completed_today(task: Task) -> bool:
    return task.is_completed_on_date(_get_today_string())

func is_task_completed_this_week(task: Task) -> bool:
    return get_times_per_week_progress(task) >= 1

func save_state() -> void:
    var serialized_tasks: Array = []
    for task in tasks:
        serialized_tasks.append(task.to_dict())
    var payload := {
        "tasks": serialized_tasks,
        "total_xp": total_xp,
        "dino_level": dino_level,
        "next_task_id": _next_task_id
    }
    var file := FileAccess.open(SAVE_PATH, FileAccess.WRITE)
    if file:
        file.store_string(JSON.stringify(payload))
        file.close()

func load_state() -> void:
    if not FileAccess.file_exists(SAVE_PATH):
        tasks = []
        total_xp = 0
        dino_level = 1
        _next_task_id = 1
        return
    var file := FileAccess.open(SAVE_PATH, FileAccess.READ)
    if file == null:
        return
    var content := file.get_as_text()
    file.close()
    var parse := JSON.parse_string(content)
    if typeof(parse) != TYPE_DICTIONARY:
        return
    tasks = []
    for task_data in parse.get("tasks", []):
        if typeof(task_data) == TYPE_DICTIONARY:
            tasks.append(Task.from_dict(task_data))
    total_xp = int(parse.get("total_xp", 0))
    dino_level = int(parse.get("dino_level", 1))
    _next_task_id = int(parse.get("next_task_id", _calculate_next_task_id()))
    _update_dino_level_if_needed(true)

func _calculate_next_task_id() -> int:
    var highest := 0
    for task in tasks:
        highest = max(highest, task.id)
    return highest + 1

func _update_dino_level_if_needed(force_emit: bool = false) -> void:
    var new_level := _calculate_dino_level(total_xp)
    if new_level != dino_level or force_emit:
        dino_level = new_level
        dino_level_changed.emit(dino_level)

func _calculate_dino_level(xp: int) -> int:
    var level := 1
    for i in range(LEVEL_THRESHOLDS.size()):
        if xp >= LEVEL_THRESHOLDS[i]:
            level = i + 1
    return level

func _get_today_string() -> String:
    var dict := Time.get_datetime_dict_from_system()
    return _date_dict_to_string(dict)

func _is_date_in_current_week(date_string: String) -> bool:
    var target := _parse_date_string(date_string)
    if target.is_empty():
        return false
    var target_unix := Time.get_unix_time_from_datetime(target)
    var today := Time.get_datetime_dict_from_system()
    var today_unix := Time.get_unix_time_from_datetime(today)
    var weekday := today.weekday
    var start_of_week := today_unix - weekday * 86400
    return target_unix >= start_of_week and target_unix < start_of_week + 7 * 86400

func _parse_date_string(date_string: String) -> Dictionary:
    var pieces := date_string.split("-")
    if pieces.size() != 3:
        return {}
    return {
        "year": int(pieces[0]),
        "month": int(pieces[1]),
        "day": int(pieces[2]),
        "hour": 0,
        "minute": 0,
        "second": 0
    }

func _date_dict_to_string(dict: Dictionary) -> String:
    return "%04d-%02d-%02d" % [int(dict.get("year", 0)), int(dict.get("month", 0)), int(dict.get("day", 0))]
