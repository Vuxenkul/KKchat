extends Control

@onready var dino_texture: TextureRect = $VBoxContainer/DinoFrame/DinoMargin/DinoTexture
@onready var info_label: Label = $VBoxContainer/InfoLabel
var _placeholder_label: Label

const DINO_TEXTURE_PATHS := {
    1: "res://dino/dino_lvl1.png",
    2: "res://dino/dino_lvl2.png",
    3: "res://dino/dino_lvl3.png",
    4: "res://dino/dino_lvl4.png"
}

func _ready() -> void:
    _setup_placeholder()
    GameState.dino_level_changed.connect(_on_dino_level_changed)
    GameState.xp_changed.connect(_on_xp_changed)
    _update_dino_visual(GameState.dino_level)
    _update_info_label()

func _setup_placeholder() -> void:
    _placeholder_label = Label.new()
    _placeholder_label.text = "Add dino sprites in res://dino/"
    _placeholder_label.autowrap_mode = TextServer.AUTOWRAP_WORD
    _placeholder_label.horizontal_alignment = HORIZONTAL_ALIGNMENT_CENTER
    _placeholder_label.vertical_alignment = VERTICAL_ALIGNMENT_CENTER
    _placeholder_label.theme_override_font_sizes["font_size"] = 16
    _placeholder_label.size_flags_horizontal = Control.SIZE_EXPAND_FILL
    _placeholder_label.size_flags_vertical = Control.SIZE_EXPAND_FILL
    dino_texture.add_child(_placeholder_label)
    _placeholder_label.set_anchors_preset(Control.PRESET_FULL_RECT)

func _on_dino_level_changed(new_level: int) -> void:
    _update_dino_visual(new_level)
    _update_info_label()

func _on_xp_changed(_new_xp: int) -> void:
    _update_info_label()

func _update_dino_visual(level: int) -> void:
    var available_levels := DINO_TEXTURE_PATHS.keys()
    available_levels.sort()
    var selected_level := available_levels[0]
    for lvl in available_levels:
        if level >= lvl:
            selected_level = lvl
    var texture_path := DINO_TEXTURE_PATHS.get(selected_level, "")
    var texture: Texture2D = null
    if ResourceLoader.exists(texture_path, "Texture2D"):
        texture = load(texture_path)
    dino_texture.texture = texture
    _placeholder_label.visible = texture == null

func _update_info_label() -> void:
    info_label.text = "Level %d • %d XP" % [GameState.dino_level, GameState.total_xp]
