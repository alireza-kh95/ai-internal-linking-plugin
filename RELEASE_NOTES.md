Fix a false ACF save warning that could appear after a nested rich-text link was successfully inserted.

The plugin now verifies the exact nested ACF value after saving. ACF's false return value is accepted only when the requested HTML was actually persisted; genuine write failures still produce an error.

Includes all existing-anchor suppression and nested ACF linking improvements from 1.3.3.

