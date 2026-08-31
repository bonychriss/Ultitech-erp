import { useState } from "react";
import { Input } from "./ui/input";
import { Button } from "./ui/button";
import { Plus } from "lucide-react";
import { motion } from "motion/react";

interface QuickAddTaskProps {
  onAdd: (title: string) => void;
}

export function QuickAddTask({ onAdd }: QuickAddTaskProps) {
  const [title, setTitle] = useState("");
  const [isFocused, setIsFocused] = useState(false);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (title.trim()) {
      onAdd(title.trim());
      setTitle("");
    }
  };

  return (
    <motion.form
      onSubmit={handleSubmit}
      className="relative"
      animate={isFocused ? { scale: 1.02 } : { scale: 1 }}
      transition={{ duration: 0.2 }}
    >
      <div className="relative">
        <Input
          type="text"
          placeholder="Quick add: Type a task and press enter..."
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          onFocus={() => setIsFocused(true)}
          onBlur={() => setIsFocused(false)}
          className={`
            pr-12 rounded-2xl h-14 text-base
            border-2 transition-all duration-200
            ${isFocused ? 'border-purple-400 shadow-lg shadow-purple-100' : 'border-gray-200'}
          `}
        />
        <Button
          type="submit"
          size="sm"
          disabled={!title.trim()}
          className="absolute right-2 top-1/2 -translate-y-1/2 rounded-xl h-10 bg-gradient-to-r from-blue-500 to-purple-600 hover:from-blue-600 hover:to-purple-700"
        >
          <Plus className="h-4 w-4" />
        </Button>
      </div>
    </motion.form>
  );
}
